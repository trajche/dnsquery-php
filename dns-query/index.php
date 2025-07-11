<?php

class DnsOverHttpsServer {
    private $records;
    
    public function __construct($recordsFile) {
        $this->records = json_decode(file_get_contents($recordsFile), true);
    }
    
    public function handleRequest() {
        header('Content-Type: application/dns-message');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST');
        header('Access-Control-Allow-Headers: Content-Type');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            return;
        }
        
        try {
            $dnsQuery = $this->getDnsQuery();
            if (!$dnsQuery) {
                http_response_code(400);
                return;
            }
            
            $response = $this->processDnsQuery($dnsQuery);
            echo $response;
            
        } catch (Exception $e) {
            http_response_code(500);
            error_log("DoH Server Error: " . $e->getMessage());
        }
    }
    
    private function getDnsQuery() {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if (!isset($_GET['dns'])) {
                return false;
            }
            return base64_decode(strtr($_GET['dns'], '-_', '+/'));
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return file_get_contents('php://input');
        }
        return false;
    }
    
    private function processDnsQuery($queryData) {
        $query = $this->parseDnsQuery($queryData);
        if (!$query) {
            return $this->buildErrorResponse();
        }
        
        $domain = $this->extractDomain($query['name']);
        $hostname = $query['name'];
        $qtype = $query['type'];
        
        $answer = $this->findRecord($domain, $hostname, $qtype);
        
        // If no local record found, fallback to Cloudflare DoH
        if (!$answer) {
            $answer = $this->queryCloudflare($queryData);
            if ($answer) {
                return $answer; // Return raw response from Cloudflare
            }
        }
        
        return $this->buildDnsResponse($query, $answer);
    }
    
    private function parseDnsQuery($data) {
        if (strlen($data) < 12) return false;
        
        $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', substr($data, 0, 12));
        if ($header['qdcount'] != 1) return false;
        
        $offset = 12;
        $name = $this->parseDnsName($data, $offset);
        $typeClass = unpack('ntype/nclass', substr($data, $offset, 4));
        
        return [
            'id' => $header['id'],
            'name' => $name,
            'type' => $typeClass['type'],
            'class' => $typeClass['class']
        ];
    }
    
    private function parseDnsName($data, &$offset) {
        $name = '';
        while (true) {
            $len = ord($data[$offset++]);
            if ($len == 0) break;
            if ($name) $name .= '.';
            $name .= substr($data, $offset, $len);
            $offset += $len;
        }
        return $name;
    }
    
    private function extractDomain($hostname) {
        $parts = explode('.', $hostname);
        if (count($parts) >= 2) {
            return implode('.', array_slice($parts, -2));
        }
        return $hostname;
    }
    
    private function findRecord($domain, $hostname, $qtype) {
        if (!isset($this->records[$domain][$hostname])) {
            return null;
        }
        
        $records = $this->records[$domain][$hostname];
        $qtypeName = $this->getQtypeName($qtype);
        
        if (!isset($records[$qtypeName])) {
            return null;
        }
        
        return [
            'type' => $qtype,
            'data' => $records[$qtypeName]
        ];
    }
    
    private function getQtypeName($qtype) {
        $types = [
            1 => 'A',
            28 => 'AAAA',
            5 => 'CNAME',
            15 => 'MX',
            16 => 'TXT',
            2 => 'NS'
        ];
        return $types[$qtype] ?? 'UNKNOWN';
    }
    
    private function buildDnsResponse($query, $answer) {
        $flags = 0x8180; // Response, recursion desired and available
        if (!$answer) $flags |= 0x0003; // NXDOMAIN
        
        $header = pack('nnnnnn', 
            $query['id'],
            $flags,
            1, // Questions
            $answer ? 1 : 0, // Answers
            0, // Authority
            0  // Additional
        );
        
        $question = $this->encodeDnsName($query['name']) . pack('nn', $query['type'], $query['class']);
        
        $answerSection = '';
        if ($answer) {
            $answerSection = $this->buildAnswerRecord($query['name'], $answer);
        }
        
        return $header . $question . $answerSection;
    }
    
    private function buildAnswerRecord($name, $answer) {
        $encodedName = $this->encodeDnsName($name);
        $ttl = 300; // 5 minutes
        
        $rdata = $this->encodeRdata($answer['type'], $answer['data']);
        
        return $encodedName . pack('nnNn', $answer['type'], 1, $ttl, strlen($rdata)) . $rdata;
    }
    
    private function encodeRdata($type, $data) {
        switch ($type) {
            case 1: // A record
                return inet_pton($data);
            case 28: // AAAA record
                return inet_pton($data);
            case 5: // CNAME
                return $this->encodeDnsName($data);
            case 15: // MX
                if (is_array($data)) {
                    $mx = $data[0]; // Use first MX record
                    return pack('n', $mx['priority']) . $this->encodeDnsName($mx['target']);
                }
                break;
            case 16: // TXT
                if (is_array($data)) {
                    $txt = $data[0]; // Use first TXT record
                } else {
                    $txt = $data;
                }
                return chr(strlen($txt)) . $txt;
            case 2: // NS
                if (is_array($data)) {
                    return $this->encodeDnsName($data[0]); // Use first NS record
                }
                return $this->encodeDnsName($data);
        }
        return '';
    }
    
    private function encodeDnsName($name) {
        if ($name === '') return "\0";
        
        $encoded = '';
        $parts = explode('.', $name);
        foreach ($parts as $part) {
            $encoded .= chr(strlen($part)) . $part;
        }
        $encoded .= "\0";
        return $encoded;
    }
    
    private function queryCloudflare($queryData) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://cloudflare-dns.com/dns-query',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $queryData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/dns-message',
                'Accept: application/dns-message'
            ],
            CURLOPT_USERAGENT => 'PHP-DoH-Server/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response !== false && $httpCode === 200) {
            return $response;
        }
        
        return null;
    }
    
    private function buildErrorResponse() {
        return pack('nnnnnn', 0, 0x8183, 0, 0, 0, 0); // Server failure
    }
}

// Initialize and run the server
$server = new DnsOverHttpsServer(__DIR__ . '/../dns_records.json');
$server->handleRequest();
?>