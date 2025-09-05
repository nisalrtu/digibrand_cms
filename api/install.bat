@echo off
echo ====================================
echo DigiBrand CRM API Installation
echo ====================================
echo.

echo Step 1: Copying API files...
if not exist "C:\xampp\htdocs\digibrandcrm" mkdir "C:\xampp\htdocs\digibrandcrm"
if not exist "C:\xampp\htdocs\digibrandcrm\api" mkdir "C:\xampp\htdocs\digibrandcrm\api"

xcopy /E /Y "C:\xampp\htdocs\InvoiceManagerApp\backend_api\*" "C:\xampp\htdocs\digibrandcrm\api\"

echo API files copied successfully!
echo.

echo Step 2: Setup instructions
echo.
echo 1. Start XAMPP (Apache and MySQL)
echo 2. Open phpMyAdmin (http://localhost/phpmyadmin)
echo 3. Create a new database named 'digibrandcrm'
echo 4. Import the SQL schema from the database structure provided
echo 5. Update database credentials in config/database.php if needed
echo 6. Test the API: http://localhost/digibrandcrm/api/
echo.

echo Installation completed!
echo.
echo Default login credentials:
echo Username: admin
echo Password: admin123
echo.
echo IMPORTANT: Change the default password after first login!
echo.
pause
