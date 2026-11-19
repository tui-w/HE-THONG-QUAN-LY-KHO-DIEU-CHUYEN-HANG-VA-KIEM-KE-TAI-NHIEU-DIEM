# 📦 BÀI TẬP LỚN PHÁT TRIỂN PHẦN MỀM MÃ NGUỒN MỞ (FIT-DNU)
## ĐỀ TÀI 10: XÂY DỰNG HỆ THỐNG QUẢN LÝ KHO, ĐIỀU CHUYỂN HÀNG VÀ KIỂM KÊ TẠI NHIỀU ĐIỂM

<div align="center">

![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue?style=for-the-badge&logo=php)
![OS](https://img.shields.io/badge/OS-Ubuntu%20%2F%20Linux-orange?style=for-the-badge&logo=ubuntu)
![Database](https://img.shields.io/badge/Database-SQLite%20%2F%20MySQL-lightgrey?style=for-the-badge&logo=sqlite)
![Automated Tests](https://img.shields.io/badge/Tests-9%2F9%20PASS-brightgreen?style=for-the-badge&logo=checkmarx)
![License](https://img.shields.io/badge/License-MIT-green?style=for-the-badge)

</div>

---

## 👥 Thông tin nhóm thực hiện
- **Lớp**: CNTT-18-10
- **Nhóm**: 10
- **Khoa**: Công nghệ Thông tin — Trường Đại học Đại Nam (FIT-DNU)
- **Đề tài bài tập lớn**: Đề tài 10 — Hệ thống quản lý kho, điều chuyển hàng và kiểm kê tại nhiều điểm.

| STT | Họ và tên | Mã sinh viên | Vai trò | Phân công nhiệm vụ |
|:---:|:---|:---:|:---:|:---|
| 1 | **Đào Thị Tươi** | 1871020639 | Trưởng nhóm | Quản lý dự án, thiết kế CSDL, Sổ phát sinh, Chức năng 1 & 2 |
| 2 | **Nguyễn Văn An** | 1871020003 | Thành viên | Xử lý Giao dịch điều chuyển 2 kho (Kỹ thuật 2), Kiểm kê (Chức năng 4) |
| 3 | **Lê Đình Huy** | 1871020307 | Thành viên | Thẻ kho & Báo cáo đối chiếu (Chức năng 5), Bộ test tự động 5 phép thử |

---

## 🌟 Tổng quan sản phẩm & 5 Nhóm chức năng đạt Rubric

Hệ thống được thiết kế theo đúng quy chuẩn phát triển phần mềm mã nguồn mở kế thừa từ chuỗi 5 bài Lab, tách biệt nghiệp vụ khỏi giao diện và lưu trữ, tuân thủ nguyên tắc ACID trong cơ sở dữ liệu:

1. **Chức năng 1: Quản lý Danh mục & Phân quyền thao tác theo điểm kho**
   - Quản lý 3 điểm kho hoạt động: Kho Tổng Miền Bắc (`KHO-HN`), Kho Trung Chuyển Miền Trung (`KHO-DN`), Kho Phân Phối Miền Nam (`KHO-HCM`).
   - Quản lý 30 mặt hàng phân theo các nhóm hàng, đơn vị tính, định mức an toàn và quản lý theo mã lô hàng (NSX, HSD).
   - Kiểm soát quyền hạn người dùng theo từng kho (Server-side RBAC) chặt chẽ tại phía máy chủ (Xem, Tạo phiếu, Duyệt phiếu).

2. **Chức năng 2: Quản lý Phiếu Nhập / Xuất & Sổ phát sinh kho (Ledger)**
   - Lập và thẩm định phiếu Nhập kho (IN), phiếu Xuất kho (OUT).
   - Tra cứu số tồn tức thời từ các dòng phát sinh đã ghi nhận, đảm bảo số tồn luôn khớp chính xác với lịch sử.

3. **Chức năng 3: Điều chuyển hàng liên kho (Stock Transfer)**
   - Điều chuyển hàng giữa hai kho trong cùng cơ sở dữ liệu.
   - Ghi nhận đồng thời chứng từ xuất tại kho đi (`TRANSFER_OUT`) và chứng từ nhập tại kho đến (`TRANSFER_IN`) trong cùng một giao dịch nguyên tử.

4. **Chức năng 4: Kiểm kê thực tế & Xử lý chênh lệch tồn kho**
   - Lập các đợt kiểm kê, ghi nhận số lượng đếm thực tế theo từng mặt hàng/lô hàng.
   - Tự động so sánh với tồn sổ sách và tính toán chênh lệch (thừa/thiếu).
   - Tự động sinh chứng từ điều chỉnh (`ADJUST_IN` / `ADJUST_OUT`) kèm lý do giải trình rõ ràng, cập nhật lại số tồn khớp sổ sách.

5. **Chức năng 5: Sổ Thẻ kho (Stock Card), Đối chiếu & Báo cáo**
   - Tra cứu lịch sử thẻ kho chi tiết theo từng mặt hàng tại từng kho, xem số tồn lũy kế biến động theo từng chứng từ.
   - Cơ chế sửa chứng từ đã ghi nhận bằng chứng từ điều chỉnh, **tuyệt đối không xóa hay làm mất lịch sử phát sinh**.
   - Xuất báo cáo dữ liệu dạng bảng biểu HTML và tệp CSV hỗ trợ UTF-8 mở trực tiếp trên Excel.

---

## ⚡ Hai Yêu Cầu Kỹ Thuật Trọng Tâm (Rubric 2.0 điểm)

- **Yêu cầu kỹ thuật 1 (1.0 điểm)**: Thiết kế Sổ phát sinh (`stock_movements`) và giao dịch cập nhật tồn. Khi nhiều yêu cầu cùng xuất, sử dụng transaction và kiểm tra tồn tức thời để **không bao giờ cho số tồn âm**. Khi gửi lại một chứng từ đã xác nhận (Idempotent), hệ thống chặn lại và **không ghi nhận thêm lần nữa**.
- **Yêu cầu kỹ thuật 2 (1.0 điểm)**: Điều chuyển phải trừ kho xuất và cộng kho nhập trong **cùng một giao dịch database (ACID)**. Bất kỳ lỗi nào xảy ra ở giữa (mô phỏng mất mạng, ngắt kết nối hoặc exception) đều được **ROLLBACK hoàn toàn**, không làm mất hoặc nhân đôi hàng; số tồn luôn khớp sổ phát sinh.

---

## 🧪 5 Phép Thử Bắt Buộc (Rubric 1.5 điểm - Đạt 100%)

Dự án cung cấp bộ kiểm thử tự động toàn diện kiểm chứng chính xác 5 phép thử của Đề tài 10:

1. **Phép thử 1**: Kho còn 5 sản phẩm, hai yêu cầu cùng xuất 4: Chỉ một phiếu được ghi nhận thành công, phiếu sau bị từ chối với ngoại lệ `INSUFFICIENT_STOCK`, số tồn cuối cùng là 1 (không âm).
2. **Phép thử 2**: Gây lỗi giữa lúc trừ kho A và cộng kho B: Kích hoạt ngoại lệ mô phỏng crash, giao dịch Rollback hoàn toàn; số tồn kho A và B giữ nguyên 100%, không phát sinh dữ liệu rác dở dang.
3. **Phép thử 3**: Gửi lại chứng từ đã xác nhận (Tính Idempotent): Hệ thống từ chối với lỗi `VOUCHER_ALREADY_CONFIRMED`, số tồn và sổ phát sinh không bị tăng thêm hay nhân bản.
4. **Phép thử 4**: Tài khoản chỉ phụ trách kho A không được lập phiếu xuất kho B bằng yêu cầu trực tiếp: Server-side RBAC chặn đứng request với mã lỗi `PERMISSION_DENIED`.
5. **Phép thử 5**: Điều chỉnh kiểm kê có lý do và chứng từ: Số tồn sau điều chỉnh khớp 100% với công thức `SUM(quantity_in) - SUM(quantity_out)` trong sổ phát sinh.

---

## 💻 Hướng Dẫn Vận Hành Trên Ubuntu / WSL

Mở Terminal trên **Ubuntu / WSL2**, di chuyển vào thư mục dự án và sử dụng lệnh chuẩn `lab.sh`:

```bash
# 1. Di chuyển vào thư mục dự án
cd "BTL_QuanLyKho"

# 2. Kiểm tra môi trường (PHP 8.1+, Git, PDO)
bash lab.sh check

# 3. Khởi tạo cơ sở dữ liệu mẫu (>3 kho, 30 mặt hàng, >200 phát sinh)
bash lab.sh seed

# 4. Chạy bộ kiểm thử tự động 5 phép thử bắt buộc
bash lab.sh test

# 5. Chạy bộ kiểm thử mở rộng (Bảo toàn tổng tồn, Thẻ kho lũy kế)
bash lab.sh extend

# 6. Khởi động máy chủ Web cục bộ
bash lab.sh run
```

Mở trình duyệt truy cập: **`http://127.0.0.1:8110`**

---

## 🔑 Tài Khoản Đăng Nhập Mẫu (Hỗ trợ 1-Click Login)

Màn hình đăng nhập đã tích hợp sẵn bảng **Đăng nhập nhanh (1 Click)** để thầy cô và người dùng dễ dàng chuyển đổi vai trò kiểm tra:

| Tên đăng nhập | Mật khẩu | Họ và tên | Vai trò | Quyền hạn điểm kho |
|:---|:---:|:---|:---:|:---|
| `tuoi` (hoặc `admin`) | `123456` | **Đào Thị Tươi** | Admin | Toàn quyền cấu hình và quản trị cả 3 kho |
| `manager` | `123456` | **Nguyễn Văn An** | Manager | Phê duyệt chứng từ và điều chuyển cả 3 kho |
| `kho_hn` | `123456` | **Trần Đức Minh** | Staff | Chỉ được tạo và thao tác tại Kho Hà Nội |
| `kho_dn` | `123456` | **Hoàng Minh Tuấn** | Staff | Chỉ được tạo và thao tác tại Kho Đà Nẵng |
| `kho_hcm` | `123456` | **Phạm Thùy Linh** | Staff | Chỉ được tạo và thao tác tại Kho TP.HCM |
| `auditor` | `123456` | **Lê Đình Huy** | Auditor | Lập đợt kiểm kê và đối chiếu chênh lệch tại các kho |

---

## 🎬 Hướng Dẫn Trình Diễn Hai Tình Huống Nghiệp Vụ (Mục 4)

### Tình huống 1: Nhập hàng → Xác nhận chứng từ → Xuất hàng → Tra cứu số tồn & Lịch sử
1. Đăng nhập tài khoản `kho_hn`, vào mục **Phiếu Nhập / Xuất** -> Bấm **Lập phiếu mới**.
2. Chọn loại `Phiếu Nhập kho (IN)`, kho nhận `Kho Hà Nội`, chọn mặt hàng và số lượng 20. Bấm **Lưu chứng từ**.
3. Đăng nhập tài khoản `manager`, xem phiếu vừa lập ở trạng thái `DRAFT` -> Bấm nút **Duyệt**.
4. Chuyển sang tạo `Phiếu Xuất kho (OUT)` xuất 5 sản phẩm. Hệ thống trừ tồn kho an toàn.
5. Vào menu **Sổ Thẻ kho & Báo cáo** -> Chọn mặt hàng vừa thao tác -> Thẻ kho hiển thị từng dòng nhập (+20), xuất (-5) và tồn lũy kế chính xác.

### Tình huống 2: Điều chuyển giữa hai kho → Kiểm kê → Xác nhận chênh lệch → Lập chứng từ điều chỉnh
1. Vào menu **Điều chuyển 2 kho**, chọn chuyển 10 sản phẩm từ `KHO-HN` sang `KHO-DN`. Bấm thực hiện -> Cả 2 kho được cập nhật đồng thời trong một giao dịch. *(Có thể tích chọn "Bật mô phỏng lỗi crash giữa 2 kho" để biểu diễn Rollback)*.
2. Vào menu **Đợt kiểm kê & Chênh lệch** -> Bấm **Tạo đợt kiểm kê mới** tại Kho Đà Nẵng.
3. Nhập số lượng thực tế đếm được (cố tình nhập lệch +2 hoặc -1) kèm lý do giải trình.
4. Bấm **Xác nhận & Lập chứng từ điều chỉnh**. Hệ thống tự động sinh phiếu điều chỉnh (`ADJUST_IN` / `ADJUST_OUT`).
5. Tra cứu lại Sổ phát sinh: Số tồn mới nhất khớp 100% với số lượng thực tế kiểm kê mà không hề làm mất lịch sử các giao dịch trước đó.

---

## 📂 Cấu Trúc Thư Mục Dự Án

```
BTL_QuanLyKho/
├── lab.sh                     # Script điều khiển chuẩn (check, run, test, extend, seed)
├── README.md                  # Hướng dẫn chi tiết bài tập lớn
├── LICENSE                    # Giấy phép mã nguồn mở MIT
├── database/
│   └── schema.sql             # Cấu trúc bảng CSDL (users, warehouses, vouchers, stock_movements...)
├── src/
│   ├── Database.php           # Kết nối PDO (SQLite mặc định không cần cài đặt, hỗ trợ MySQL)
│   ├── Auth.php               # Quản lý phiên làm việc & kiểm tra phân quyền Server-side RBAC
│   ├── StockService.php       # Nghiệp vụ sổ phát sinh, chống âm kho, kiểm tra Idempotent
│   ├── TransferService.php    # Nghiệp vụ điều chuyển liên kho nguyên tử (ACID Rollback)
│   ├── InventoryService.php   # Nghiệp vụ kiểm kê, tính chênh lệch & sinh phiếu điều chỉnh
│   ├── ReportService.php      # Sổ thẻ kho lũy kế, tổng hợp tồn kho & xuất file CSV
│   └── Seeder.php             # Nạp dữ liệu mẫu chuẩn (>3 kho, 30 mặt hàng, >200 phát sinh)
├── public/                    # Document Root của ứng dụng Web
│   ├── index.php              # Bảng điều khiển (Dashboard KPI, hàng sắp hết, phát sinh gần nhất)
│   ├── login.php              # Đăng nhập hệ thống & 1-Click User Switcher
│   ├── logout.php             # Đăng xuất
│   ├── warehouses.php         # Điểm kho & Ma trận phân quyền thao tác (RBAC Matrix)
│   ├── items.php              # Danh mục 30 mặt hàng, nhóm hàng & quản lý lô
│   ├── vouchers.php           # Lập và duyệt phiếu Nhập / Xuất kho
│   ├── voucher_detail.php     # Chi tiết chứng từ & Nút test Idempotent trực quan
│   ├── transfer.php           # Lệnh điều chuyển 2 kho & Nút test Crash Rollback
│   ├── inventory.php          # Danh sách đợt kiểm kê kho
│   ├── inventory_detail.php   # Bảng đối chiếu chênh lệch thực tế vs sổ sách
│   ├── reports.php            # Sổ Thẻ kho lũy kế, báo cáo tồn kho & xuất CSV
│   ├── api.php                # API AJAX tra cứu tồn kho theo thời gian thực
│   └── assets/css/style.css   # Giao diện hiện đại (Modern Glassmorphism & Material tokens)
├── templates/
│   ├── header.php             # Thanh điều hướng Navbar, Sidebar & Thông báo Flash
│   └── footer.php             # Chân trang bản quyền FIT-DNU
├── tests/
│   ├── run.php                # Bộ kiểm thử tự động 5 phép thử bắt buộc (TESTS=9 FAILED=0)
│   └── extension.php          # Bộ kiểm thử mở rộng (Bảo toàn tổng tồn, Thẻ kho lũy kế)
└── docs/
    └── HUONG_DAN.md           # Hướng dẫn chi tiết, sơ đồ ERD, kiến trúc hệ thống
```
