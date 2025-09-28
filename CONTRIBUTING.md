# Contributing to Stock Transfers System

Thank you for considering contributing to the Stock Transfers System! This document outlines the process for contributing to this project.

## Code of Conduct

This project adheres to professional standards expected in an enterprise environment. Please be respectful and constructive in all interactions.

## Development Environment Setup

### Prerequisites

- PHP 8.1 or higher
- MariaDB 10.5 or higher
- Composer
- Git
- Access to CIS development environment

### Local Setup

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
   # Edit .env with your local development settings
   ```

4. **Run tests to verify setup**:
   ```bash
   composer test
   ```

## Contribution Workflow

### 1. Issue Discussion

- Check existing issues before creating new ones
- For new features, create an issue first to discuss the approach
- For bugs, provide clear reproduction steps

### 2. Branch Strategy

- `main` - Production-ready code
- `develop` - Development integration branch
- `feature/*` - New features
- `bugfix/*` - Bug fixes
- `hotfix/*` - Critical production fixes

### 3. Making Changes

1. **Create a feature branch**:
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make your changes**:
   - Follow PSR-12 coding standards
   - Write tests for new functionality
   - Update documentation as needed

3. **Run quality checks**:
   ```bash
   composer build  # Runs CS fixer, static analysis, and tests
   ```

4. **Commit your changes**:
   ```bash
   git add .
   git commit -m "Add: Brief description of changes"
   ```

### 4. Pull Request Process

1. **Push your branch**:
   ```bash
   git push origin feature/your-feature-name
   ```

2. **Create a Pull Request**:
   - Use a clear, descriptive title
   - Include a detailed description of changes
   - Reference any related issues
   - Add screenshots for UI changes

3. **PR Requirements**:
   - All tests must pass
   - Code coverage should not decrease
   - Code must follow PSR-12 standards
   - Documentation must be updated

## Coding Standards

### PHP Standards

- Follow PSR-12 coding style
- Use strict typing: `declare(strict_types=1);`
- Document all public methods with PHPDoc
- Use meaningful variable and method names
- Keep methods small and focused (single responsibility)

### Example:
```php
<?php
declare(strict_types=1);

namespace VapeShed\StockTransfers\Services;

/**
 * Service for managing stock transfers between locations
 */
class TransferService
{
    /**
     * Create a new stock transfer
     *
     * @param array $transferData Transfer details
     * @return array Transfer creation result
     * @throws \InvalidArgumentException When data is invalid
     */
    public function createTransfer(array $transferData): array
    {
        // Implementation here
    }
}
```

### Database Standards

- Use parameterized queries only
- Follow consistent naming conventions
- Add proper indexes for performance
- Include migration rollback methods

### Frontend Standards

- Use semantic HTML
- Follow BEM CSS methodology
- Ensure mobile responsiveness
- Test across browsers
- Maintain accessibility standards (WCAG 2.1 AA)

## Testing Requirements

### Test Coverage

- All new code must have tests
- Aim for >90% code coverage
- Include both positive and negative test cases
- Test edge cases and error conditions

### Test Types

1. **Unit Tests** - Test individual methods/functions
2. **Integration Tests** - Test component interactions
3. **Feature Tests** - Test complete user workflows

### Running Tests

```bash
# All tests
composer test

# With coverage report
composer test-coverage

# Specific test suite
./vendor/bin/phpunit tests/Unit
```

## Documentation Requirements

### Code Documentation

- All public methods must have PHPDoc comments
- Include parameter types and return types
- Document exceptions that may be thrown
- Explain complex business logic

### User Documentation

- Update README.md for new features
- Add API documentation for new endpoints
- Include examples and usage instructions
- Update CHANGELOG.md

### Architecture Documentation

- Document significant design decisions
- Update system diagrams when architecture changes
- Explain integration points with external systems

## Review Process

### Code Review Checklist

- [ ] Code follows PSR-12 standards
- [ ] All tests pass
- [ ] Code coverage is maintained
- [ ] Documentation is updated
- [ ] No security vulnerabilities introduced
- [ ] Performance implications considered
- [ ] Error handling is appropriate
- [ ] Backwards compatibility maintained

### Security Considerations

- Never commit credentials or secrets
- Validate and sanitize all inputs
- Use parameterized queries
- Implement proper error handling
- Follow principle of least privilege

## Release Process

### Version Numbers

We follow Semantic Versioning (SemVer):
- MAJOR.MINOR.PATCH
- MAJOR: Breaking changes
- MINOR: New features (backwards compatible)
- PATCH: Bug fixes (backwards compatible)

### Release Steps

1. Update CHANGELOG.md
2. Update version in composer.json
3. Tag the release
4. Deploy to staging
5. Run smoke tests
6. Deploy to production

## Getting Help

### Internal Resources

- **CIS Wiki**: https://wiki.vapeshed.co.nz
- **Staff Portal**: https://staff.vapeshed.co.nz
- **Tech Lead**: pearce.stephens@ecigdis.co.nz

### Development Resources

- **PSR-12**: https://www.php-fig.org/psr/psr-12/
- **PHPUnit**: https://phpunit.de/documentation.html
- **Composer**: https://getcomposer.org/doc/

## Recognition

Contributors will be recognized in:
- CHANGELOG.md for significant contributions
- README.md contributors section
- Internal company communications for major features

Thank you for contributing to The Vape Shed's success!