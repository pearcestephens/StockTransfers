# Stock Transfers System

**Ecigdis Limited / The Vape Shed - Stock Transfer Management Module**

A comprehensive PHP-based stock transfer system for managing inventory movements between stores, warehouses, and suppliers across The Vape Shed's 17+ locations in New Zealand.

## 🏢 Company Overview

**Company**: Ecigdis Limited  
**Trading As**: The Vape Shed  
**Locations**: 17+ stores across New Zealand  
**Founded**: 2015  

## 📋 System Overview

This module handles:

- **Inter-store transfers** - Moving stock between retail locations
- **Warehouse distribution** - Central to store fulfillment
- **Freight management** - NZ Post, CourierPost, and courier integrations
- **Real-time tracking** - Live status updates and notifications
- **Pack & ship automation** - Label generation and tracking

## 🛠 Technical Stack

- **Language**: PHP 8.1+ (strict typing, PSR-12)
- **Database**: MariaDB 10.5+
- **Framework**: Custom MVC with CIS integration
- **Frontend**: Bootstrap 4.2 + ES6 modules
- **APIs**: Vend POS, NZ Post, CourierPost
- **Infrastructure**: Cloudways (Nginx/PHP-FPM)

## 📁 Project Structure

```
├── output.php              # Main API endpoint
├── stock/                  # Core transfer modules
│   ├── receive.php         # Receiving interface
│   ├── pack.php           # Packing interface
│   ├── api/               # API endpoints
│   ├── services/          # Business logic
│   ├── views/             # UI templates
│   ├── tools/             # Utilities & validators
│   └── sql/               # Database migrations
├── docs/                  # Documentation
├── tests/                 # Test suites
└── config/               # Configuration files
```

## 🚀 Quick Start

### Prerequisites

- PHP 8.1+
- MariaDB 10.5+
- Composer
- CIS framework access

### Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/pearcestephens/StockTransfers.git
   cd StockTransfers
   ```

2. **Install dependencies**:
   ```bash
   composer install
   ```

3. **Configure environment**:
   ```bash
   cp .env.example .env
   # Edit .env with your database and API credentials
   ```

4. **Run migrations**:
   ```bash
   php migrate.php up
   ```

5. **Start development server**:
   ```bash
   php -S localhost:8000 -t public
   ```

## 🔧 Configuration

Key configuration files:

- `.env` - Environment variables and secrets
- `config/app.php` - Application settings
- `config/database.php` - Database connections
- `config/freight.php` - Carrier configurations

## 📊 Features

### Core Functionality

- ✅ **Transfer Creation** - Easy wizard-based transfer creation
- ✅ **Multi-location Support** - 17+ stores with outlet mapping
- ✅ **Real-time Tracking** - Live status updates via webhooks
- ✅ **Freight Integration** - NZ Post, CourierPost APIs
- ✅ **Label Generation** - Automated shipping labels
- ✅ **Pack & Ship** - Streamlined fulfillment workflow
- ✅ **Receiving Interface** - Quick receiving with validation
- ✅ **Audit Logging** - Complete transfer history tracking

### Advanced Features

- 🔄 **Box Allocation Engine** - Smart packing optimization
- 🏷️ **Dynamic Label Templates** - Customizable label formats
- 🔒 **Pack Locking System** - Prevents concurrent modifications
- 📊 **Analytics Dashboard** - Transfer performance metrics
- 🤖 **AI Integration** - Smart routing suggestions
- 📱 **Mobile Responsive** - Works on tablets and phones

## 🔒 Security

- **Authentication** - CIS user authentication required
- **Authorization** - Role-based permissions (Manager, Staff)
- **Input Validation** - All inputs sanitized and validated
- **SQL Injection Prevention** - Parameterized queries only
- **CSRF Protection** - All forms CSRF protected
- **Audit Trails** - All actions logged with user tracking

## 🧪 Testing

Run the test suite:

```bash
# Unit tests
./vendor/bin/phpunit tests/Unit

# Integration tests
./vendor/bin/phpunit tests/Integration

# All tests
./vendor/bin/phpunit
```

## 📈 Performance

- **Response Times**: < 500ms p95 for API calls
- **Concurrent Users**: 50+ simultaneous users supported
- **Database**: Optimized queries with covering indexes
- **Caching**: Redis caching for frequently accessed data

## 🚦 Deployment

### Production Checklist

- [ ] Environment variables configured
- [ ] Database migrations applied
- [ ] SSL certificates installed
- [ ] Error logging enabled
- [ ] Performance monitoring active
- [ ] Backup procedures verified

### Staging Deployment

```bash
# Deploy to staging
./deploy/staging.sh

# Run smoke tests
./tests/smoke-tests.sh
```

### Production Deployment

```bash
# Deploy to production (requires approval)
./deploy/production.sh
```

## 📝 API Documentation

### Authentication

All API endpoints require authentication via CIS session or API key.

### Core Endpoints

- `GET /api/transfers` - List transfers
- `POST /api/transfers` - Create transfer
- `GET /api/transfers/{id}` - Get transfer details
- `PUT /api/transfers/{id}` - Update transfer
- `POST /api/transfers/{id}/pack` - Pack items
- `POST /api/transfers/{id}/ship` - Generate shipping labels

See full API documentation at `/docs/api.md`

## 🐛 Troubleshooting

### Common Issues

1. **Database Connection Errors**
   - Check `.env` database credentials
   - Verify database server is accessible
   - Check firewall settings

2. **API Integration Failures**
   - Verify API keys in `.env`
   - Check network connectivity
   - Review error logs in `logs/`

3. **Permission Denied**
   - Check file permissions (755 for directories, 644 for files)
   - Verify web server user ownership

### Debug Mode

Enable debug mode in `.env`:
```
APP_DEBUG=true
LOG_LEVEL=debug
```

## 📞 Support

### Internal Support

- **IT Manager**: [TBC]
- **Lead Developer**: Pearce Stephens <pearce.stephens@ecigdis.co.nz>
- **CIS Portal**: https://staff.vapeshed.co.nz

### Documentation

- **Wiki**: https://wiki.vapeshed.co.nz
- **API Docs**: `/docs/api.md`
- **Troubleshooting**: `/docs/troubleshooting.md`

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Code Standards

- Follow PSR-12 coding standards
- Include unit tests for new features
- Update documentation as needed
- Use conventional commit messages

## 📄 License

Proprietary software owned by Ecigdis Limited. All rights reserved.

## 🔄 Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history and release notes.

## 🎯 Roadmap

### Upcoming Features

- [ ] **Mobile App** - Native iOS/Android apps
- [ ] **Advanced Analytics** - ML-powered insights
- [ ] **Multi-tenant Support** - White-label for other retailers
- [ ] **API v2** - GraphQL endpoint
- [ ] **Blockchain Integration** - Supply chain transparency

---

**Made with ❤️ by The Vape Shed Team**  
*Helping New Zealand vape better since 2015*