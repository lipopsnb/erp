<?php
// Truy cập: http://localhost/erp/generate_hash.php
// Sau khi dùng xong thì XÓA file này đi!

$password = '123456';
$hash = password_hash($password, PASSWORD_DEFAULT);
echo "<h3>Hash của '123456':</h3>";
echo "<code style='font-size:16px; background:#f0f0f0; padding:10px; display:block;'>$hash</code>";
echo "<br><p>Copy hash này vào câu SQL bên dưới:</p>";

// Tự động tạo câu UPDATE luôn
echo "<h3>Chạy SQL này trong phpMyAdmin:</h3>";
echo "<textarea style='width:100%;height:300px;font-family:monospace;'>";
$users = ['giamdoc','ketoan','quanly','quanlykho','quanlysx','nhanvien1','nhanvien2'];
foreach ($users as $u) {
    echo "UPDATE users SET password_hash = '$hash' WHERE username = '$u';\n";
}
echo "</textarea>";
?>