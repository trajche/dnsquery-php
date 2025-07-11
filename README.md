# PHP DNS-over-HTTPS Server

A lightweight DNS-over-HTTPS (DoH) server implementation in PHP that serves custom DNS records with fallback to Cloudflare DNS.

## Features

- **DNS-over-HTTPS Support**: Handles both GET and POST requests according to RFC 8484
- **Custom DNS Records**: Serve your own DNS records from a JSON configuration file
- **Cloudflare Fallback**: Automatically forwards unknown queries to Cloudflare DoH for resolution
- **Multiple Record Types**: Supports A, AAAA, CNAME, MX, TXT, and NS records
- **CORS Support**: Includes proper CORS headers for web browser compatibility
- **Standards Compliant**: Implements DNS wire format and proper HTTP status codes

## Installation

1. Clone or download this repository to your web server
2. Ensure PHP with cURL extension is installed
3. Configure your web server to serve the files
4. Customize `dns_records.json` with your DNS records

### Web Server Configuration

#### Apache
Add to your `.htaccess` file:
```apache
RewriteEngine On
RewriteRule ^dns-query$ dns-query/index.php [L]
```

#### Nginx
Add to your server configuration:
```nginx
location /dns-query {
    try_files $uri $uri/ /dns-query/index.php;
}
```

## Configuration

### DNS Records

Edit `dns_records.json` to define your custom DNS records:

```json
{
  "yourdomain.com": {
    "yourdomain.com": {
      "A": "192.168.1.100",
      "AAAA": "2001:db8::1",
      "MX": [
        {"priority": 10, "target": "mail.yourdomain.com"}
      ],
      "TXT": [
        "v=spf1 include:_spf.google.com ~all"
      ]
    },
    "www.yourdomain.com": {
      "CNAME": "yourdomain.com"
    }
  }
}
```

### Supported Record Types

- **A**: IPv4 addresses
- **AAAA**: IPv6 addresses  
- **CNAME**: Canonical name records
- **MX**: Mail exchange records (with priority)
- **TXT**: Text records (supports multiple values)
- **NS**: Name server records

## Usage

### Testing with curl

```bash
# Test A record lookup
curl -H "Accept: application/dns-message" \
     "https://yourserver.com/dns-query?dns=$(echo -n '\x00\x00\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x07example\x03com\x00\x00\x01\x00\x01' | base64 -w0)"

# Test with POST method
echo -n '\x00\x00\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x07example\x03com\x00\x00\x01\x00\x01' | \
curl -X POST \
     -H "Content-Type: application/dns-message" \
     -H "Accept: application/dns-message" \
     --data-binary @- \
     "https://yourserver.com/dns-query"
```

### Browser Configuration

Configure your browser to use this DoH server:
- **URL**: `https://yourserver.com/dns-query`
- **Method**: POST (recommended) or GET

### DNS Client Libraries

Most DNS-over-HTTPS client libraries can use this server by pointing to your endpoint URL.

## Architecture

- **Entry Point**: `dns-query/index.php` - Main DoH server implementation
- **Records Database**: `dns_records.json` - JSON file containing DNS records
- **Fallback**: Queries not found locally are forwarded to Cloudflare DoH (1.1.1.1)

### Request Flow

1. Client sends DoH query to `/dns-query`
2. Server parses DNS wire format message
3. Looks up record in local `dns_records.json`
4. If found, returns custom record
5. If not found, forwards to Cloudflare DoH
6. Returns appropriate DNS response

## Security Considerations

- This server trusts the local `dns_records.json` file completely
- Cloudflare is used as upstream resolver for unknown domains
- CORS is enabled for all origins (adjust as needed for production)
- Consider implementing authentication for production deployments
- Monitor logs for potential abuse

## Performance

- Lightweight PHP implementation with minimal dependencies
- Local records served directly from memory
- Cloudflare fallback provides global DNS coverage
- Consider adding caching for better performance at scale

## Development

### File Structure
```
/
├── dns-query/
│   └── index.php          # DoH server implementation
├── dns_records.json       # DNS records configuration
├── index.html            # (placeholder)
└── README.md             # This file
```

### Adding New Record Types

To support additional DNS record types:
1. Add the type code to `getQtypeName()` method
2. Implement encoding logic in `encodeRdata()` method
3. Update JSON schema documentation

## Troubleshooting

### Common Issues

- **500 Errors**: Check PHP error logs and ensure cURL extension is installed
- **Invalid Responses**: Verify JSON syntax in `dns_records.json`
- **Cloudflare Fallback Fails**: Check network connectivity and firewall rules

### Debugging

Enable PHP error reporting and check server logs:
```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

## License

This project is provided as-is for educational and development purposes. Please review and test thoroughly before production use.

## Contributing

Contributions are welcome! Please ensure proper testing and documentation for any new features.