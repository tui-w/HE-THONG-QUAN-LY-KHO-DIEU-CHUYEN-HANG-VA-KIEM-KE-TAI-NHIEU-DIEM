@echo off
chcp 65001 >nul
title KHỞI TẠO CƠ SỞ DỮ LIỆU MYSQL / PHPMYADMIN - NHÓM 10

echo =================================================================
echo   KHỞI TẠO CƠ SỞ DỮ LIỆU MYSQL / PHPMYADMIN (ĐỀ TÀI 10)
echo   Nhập Schema và Dữ liệu mẫu vào MySQL XAMPP
echo =================================================================
echo.

set PHP_BIN=e:\xampp\php\php.exe
set MYSQL_START=e:\xampp\mysql_start.bat

if not exist "%PHP_BIN%" (
    echo [!] Không tìm thấy PHP tại %PHP_BIN%
    pause
    exit /b 1
)

echo [1/3] Khởi động dịch vụ MySQL của XAMPP...
if exist "%MYSQL_START%" (
    start "" "%MYSQL_START%"
    timeout /t 3 /nobreak >nul
)

echo [2/3] Chạy script khởi tạo CSDL qlkho_nhom10 & warehouse_db...
"%PHP_BIN%" "%~dp0setup_mysql.php"

echo.
echo [3/3] Quá trình hoàn tất. Bạn có thể mở phpMyAdmin tại http://localhost/phpmyadmin
echo =================================================================
pause
