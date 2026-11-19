@echo off
chcp 65001 > nul
title FIT-DNU Warehouse Management - Đề tài 10
echo ========================================================
echo    HỆ THỐNG QUẢN LÝ KHO ĐA ĐIỂM - ĐỀ TÀI 10 (FIT-DNU)
echo ========================================================
echo.
cd /d "%~dp0"

set PHP_BIN=php
where php >nul 2>nul
if %errorlevel% neq 0 (
    if exist "e:\xampp\php\php.exe" (
        set PHP_BIN=e:\xampp\php\php.exe
    ) else if exist "c:\xampp\php\php.exe" (
        set PHP_BIN=c:\xampp\php\php.exe
    ) else if exist "d:\xampp\php\php.exe" (
        set PHP_BIN=d:\xampp\php\php.exe
    ) else (
        echo [!] Không tìm thấy lệnh PHP trong PATH hoặc XAMPP.
        echo     Vui lòng cài đặt PHP hoặc thêm đường dẫn PHP vào biến môi trường PATH.
        pause
        exit /b 1
    )
)

echo [1/2] Khởi tạo cơ sở dữ liệu mẫu...
"%PHP_BIN%" tools/init-db.php

echo.
echo [2/2] Khởi động máy chủ Web tại http://127.0.0.1:8110 ...
echo Giữ cửa sổ này mở trong khi sử dụng. Đóng cửa sổ để dừng máy chủ.
echo.
start http://127.0.0.1:8110
"%PHP_BIN%" -S 127.0.0.1:8110 -t public
pause

