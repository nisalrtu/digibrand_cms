# DigiBrand CRM API

This is the backend API for the Invoice Manager React Native app.

## Setup Instructions

### 1. Copy API Files

Copy all files from `backend_api` folder to your web server:
```
C:\xampp\htdocs\InvoiceManagerApp\backend_api\* → C:\xampp\htdocs\digibrandcrm\api\
```

### 2. Database Setup

1. Create a new MySQL database named `digibrandcrm`
2. Run the SQL script provided to create all tables
3. Update database credentials in `config/database.php`

### 3. Configuration

Update the following files:

#### config/database.php
```php
private $host = "localhost";
private $db_name = "digibrandcrm";
private $username = "root";
private $password = "";
```

#### config/config.php
```php
define('JWT_SECRET', 'your-secret-key-here-change-in-production');
define('API_BASE_URL', 'https://crm.digibrandlk.com/api');
```

### 4. Web Server Configuration

For Apache, ensure mod_rewrite is enabled and .htaccess files are allowed.

### 5. File Permissions

Set appropriate permissions for the uploads directory:
```bash
chmod 755 uploads/
```

## API Endpoints

### Authentication
- `POST /auth/login` - User login
- `POST /auth/logout` - User logout
- `GET /auth/profile` - Get user profile
- `PUT /auth/profile` - Update user profile
- `POST /auth/change-password` - Change password
- `POST /auth/register` - Register new user (Admin only)

### Clients
- `GET /clients` - Get all clients
- `GET /clients/{id}` - Get client by ID
- `POST /clients` - Create new client
- `PUT /clients/{id}` - Update client
- `DELETE /clients/{id}` - Delete client
- `PUT /clients/{id}/toggle-status` - Toggle client status
- `GET /clients/{id}/stats` - Get client statistics

### Dashboard
- `GET /dashboard/stats` - Get dashboard statistics
- `GET /dashboard/activity` - Get recent activity
- `GET /dashboard/revenue-chart` - Get revenue chart data
- `GET /dashboard/invoice-status` - Get invoice status breakdown
- `GET /dashboard/today-stats` - Get today's statistics

## Authentication

All API endpoints (except login) require JWT authentication. Include the token in the Authorization header:

```
Authorization: Bearer YOUR_JWT_TOKEN
```

## Response Format

All API responses follow this format:

```json
{
    "success": true,
    "message": "Success message",
    "data": {...},
    "timestamp": "2025-09-04 10:30:00"
}
```

## Error Handling

Error responses include appropriate HTTP status codes and error messages:

```json
{
    "success": false,
    "message": "Error message",
    "data": null,
    "timestamp": "2025-09-04 10:30:00"
}
```

## Testing

You can test the API using tools like Postman or curl:

```bash
# Test API info endpoint
curl https://crm.digibrandlk.com/api/

# Login
curl -X POST https://crm.digibrandlk.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'
```

## Security Features

- JWT token authentication
- Password hashing with PHP's password_hash()
- CORS headers for cross-origin requests
- Input validation and sanitization
- SQL injection prevention with prepared statements
- Role-based access control

## File Structure

```
api/
├── config/
│   ├── database.php
│   └── config.php
├── controllers/
│   ├── AuthController.php
│   ├── ClientController.php
│   └── DashboardController.php
├── models/
│   ├── User.php
│   └── Client.php
├── middleware/
│   └── AuthMiddleware.php
├── utils/
│   ├── Response.php
│   └── JWTHelper.php
├── uploads/
├── .htaccess
└── index.php
```

## Default Login

- Username: `admin`
- Password: `admin123`

**Important:** Change the default password after first login!
