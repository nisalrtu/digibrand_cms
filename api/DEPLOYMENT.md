# 🚀 Deployment Guide for DigiBrand CRM API

## 📋 **Prerequisites**
- FTP/cPanel access to your hosting server (crm.digibrandlk.com)
- MySQL database access
- Your database credentials (which you already have)

## 🗄️ **Database Setup**

### **1. Local Database (for testing)**
- Database: `digibrand_crm`
- Username: `root`
- Password: (empty)
- Host: `localhost`

### **2. Production Database (hosting)**
- Database: `pgmocpbh_invoice`
- Username: `pgmocpbh_invoice`
- Password: `Bandaranayake123`
- Host: `localhost`

## 📁 **File Deployment Steps**

### **Step 1: Prepare Files for Production**

1. **Update database.php for production:**
   ```php
   // In config/database.php, change to production settings:
   private $host = 'localhost'; 
   private $db_name = 'pgmocpbh_invoice';
   private $username = 'pgmocpbh_invoice';
   private $password = 'Bandaranayake123';
   ```

2. **Update config.php for production:**
   ```php
   // Change JWT secret key
   define('JWT_SECRET', 'your-very-secure-secret-key-2025');
   
   // Disable error display
   ini_set('display_errors', 0);
   ```

### **Step 2: Upload Files**

Upload all files from `C:\xampp\htdocs\InvoiceManagerApp\backend_api\` to your hosting:

```
📁 public_html/
└── 📁 api/
    ├── 📁 config/
    │   ├── database.php
    │   └── config.php
    ├── 📁 controllers/
    │   ├── AuthController.php
    │   └── ClientController.php
    ├── 📁 models/
    │   ├── User.php
    │   └── Client.php
    ├── 📁 middleware/
    │   └── AuthMiddleware.php
    ├── 📁 utils/
    │   ├── Response.php
    │   └── JWTHelper.php
    ├── 📁 uploads/ (create this folder, set permissions 755)
    ├── 📁 logs/ (create this folder, set permissions 755)
    ├── .htaccess
    └── index.php
```

### **Step 3: Database Setup**

1. **Access your hosting phpMyAdmin**
2. **Select database `pgmocpbh_invoice`**
3. **Run the SQL schema** (the database structure you provided earlier)
4. **Verify tables are created:**
   - users
   - clients
   - projects
   - invoices
   - payments
   - expenses
   - etc.

### **Step 4: Set File Permissions**

Set proper permissions via cPanel File Manager or FTP:
```
📁 uploads/ → 755 (rwxr-xr-x)
📁 logs/ → 755 (rwxr-xr-x)
📄 .htaccess → 644 (rw-r--r--)
📄 *.php → 644 (rw-r--r--)
```

## 🧪 **Testing Your API**

### **1. Test API Info Endpoint**
```
https://crm.digibrandlk.com/api/
```
Should return JSON with API information.

### **2. Test Login**
```bash
curl -X POST https://crm.digibrandlk.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}'
```

### **3. Test in React Native App**
Your app is already configured to use `https://crm.digibrandlk.com/api` in constants.ts!

## 🔧 **Development vs Production**

### **Local Development (XAMPP)**
```
http://localhost/digibrandcrm/api/
Database: digibrand_crm (local)
```

### **Production (Hosting)**
```
https://crm.digibrandlk.com/api/
Database: pgmocpbh_invoice (hosting)
```

## 🔒 **Security Checklist**

- ✅ Change JWT secret key
- ✅ Disable error display in production
- ✅ Set proper file permissions
- ✅ Use HTTPS (SSL certificate)
- ✅ Change default admin password

## 🐛 **Troubleshooting**

### **Common Issues:**

1. **500 Internal Server Error**
   - Check .htaccess file exists
   - Verify mod_rewrite is enabled
   - Check file permissions

2. **Database Connection Error**
   - Verify database credentials
   - Ensure database exists
   - Check if MySQL service is running

3. **CORS Issues**
   - Verify .htaccess CORS headers
   - Check if Access-Control headers are set

### **Debug Mode:**
Temporarily enable error display to see detailed errors:
```php
// In config.php
ini_set('display_errors', 1);
```

## 📱 **React Native App Update**

Your React Native app should work immediately once the API is deployed since it's already configured to use:
```typescript
// In constants.ts
export const API_BASE_URL = 'https://crm.digibrandlk.com/api';
```

## 🎯 **Next Steps**

1. Deploy the API files
2. Set up the database
3. Test the endpoints
4. Continue creating remaining controllers (Projects, Invoices, Payments, Expenses)

Would you like me to continue creating the remaining API controllers?
