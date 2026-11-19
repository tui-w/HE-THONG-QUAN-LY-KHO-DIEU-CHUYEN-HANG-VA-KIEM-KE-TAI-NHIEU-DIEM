#!/usr/bin/env bash
# Đề tài 10: Hệ thống Quản lý kho, Điều chuyển hàng và Kiểm kê đa điểm
# Khoa CNTT - Trường Đại học Đại Nam (FIT-DNU)
set -u
root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)" || exit 2
cd "$root" || exit 2
port="8110"

usage() {
  printf 'Cách dùng: bash lab.sh check | run | test | extend | seed | help\n'
  printf '  check  : Kiểm tra môi trường PHP, SQLite/MySQL, Git trên Ubuntu/WSL\n'
  printf '  run    : Khởi chạy máy chủ Web cục bộ tại http://127.0.0.1:%s\n' "$port"
  printf '  test   : Chạy 5 phép thử bắt buộc theo đề tài 10 (TESTS=9 FAILED=0)\n'
  printf '  extend : Chạy bộ kiểm thử mở rộng và đối chiếu thẻ kho\n'
  printf '  seed   : Khởi tạo lại CSDL mẫu (>3 kho, 30 mặt hàng, >200 phát sinh)\n'
  printf 'Dừng máy chủ Web bằng Ctrl+C.\n'
}

need_php() {
  command -v php >/dev/null 2>&1 || { echo 'THIEU_PHP: Cài đặt php-cli (sudo apt install php-cli)'; return 2; }
  php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo 'CAN_PHP_8_1_TRO_LEN'; return 2; }
}

check_env() {
  need_php || return 2
  command -v git >/dev/null 2>&1 || { echo 'THIEU_GIT'; return 2; }
  php -r 'exit(extension_loaded("pdo_sqlite") || extension_loaded("pdo_mysql") ? 0 : 1);' || { 
    echo 'THIEU_PDO: Cài php-sqlite3 hoặc php-mysql'; return 2; 
  }
  printf 'MOI_TRUONG=SAN_SANG | Đề tài 10 - Quản lý Kho Đa Điểm\n'
  echo 'Hệ thống sẵn sàng vận hành trên Ubuntu / Linux.'
}

init_db() {
  mkdir -p .local/sessions
  php tools/init-db.php
}

case "${1:-help}" in
  check) check_env ;;
  seed)
    check_env || exit 2
    rm -f .local/warehouse.sqlite
    init_db
    ;;
  run)
    check_env || exit 2
    init_db
    echo "THU_MUC=$root"
    echo "DIA_CHI=http://127.0.0.1:$port"
    echo "Tài khoản đăng nhập mặc định: admin / 123456 (Hoặc xem danh sách trên màn hình Login)"
    echo "Giữ terminal này mở. Nhấn Ctrl+C để dừng máy chủ Web."
    mkdir -p .local/sessions || exit 2
    php -d session.name="QLKHO_BTL" -d session.save_path="$root/.local/sessions" \
      -S "0.0.0.0:$port" -t public
    ;;
  test)
    check_env || exit 2
    php tests/run.php
    ;;
  extend)
    check_env || exit 2
    php tests/extension.php
    ;;
  help|-h|--help) usage ;;
  *) usage; exit 2 ;;
esac
