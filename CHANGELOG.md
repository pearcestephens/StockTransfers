# Changelog

All notable changes to the Stock Transfers System will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial repository setup with complete project structure
- Comprehensive README with setup instructions
- Development environment configuration
- GitHub repository integration

### Changed
- Migrated from CIS monorepo to standalone StockTransfers repository

### Security
- Implemented proper .gitignore to prevent credential exposure
- Added environment variable template for secure configuration

## [1.0.0] - 2025-09-28

### Added
- Complete stock transfer management system
- Inter-store transfer capabilities
- Freight integration with NZ Post and CourierPost
- Real-time tracking and notifications
- Pack & ship automation with label generation
- Receiving interface with validation
- Box allocation engine for smart packing
- Pack locking system to prevent concurrent modifications
- Audit logging and transfer history tracking
- Mobile-responsive UI with Bootstrap 4.2
- API endpoints for programmatic access
- Multi-location support for 17+ Vape Shed stores

### Technical Features
- PHP 8.1+ with strict typing and PSR-12 compliance
- MariaDB 10.5+ database with optimized queries
- Custom MVC architecture with CIS integration
- RESTful API design with proper error handling
- Comprehensive input validation and sanitization
- CSRF protection on all forms
- Role-based permissions (Manager, Staff)
- Redis caching for performance optimization

### Infrastructure
- Cloudways hosting with Nginx/PHP-FPM
- SSL/TLS encryption for all communications
- Automated backup procedures
- Performance monitoring and alerting
- Error logging and debugging capabilities

### Security
- Authentication via CIS user system
- SQL injection prevention with parameterized queries
- Input validation and output escaping
- Secure session management
- Audit trails for all user actions

---

## Version History Legend

- **Added** for new features
- **Changed** for changes in existing functionality  
- **Deprecated** for soon-to-be removed features
- **Removed** for now removed features
- **Fixed** for any bug fixes
- **Security** for vulnerability fixes