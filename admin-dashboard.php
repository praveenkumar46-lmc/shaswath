<?php 
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] !== 'admin') {
  header('Location: login.php'); exit();
}
$conn = new mysqli("localhost", "root", "", "dvine_db");
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// Logout
if (isset($_POST['logout'])) {
  session_destroy();
  header("Location: login.php"); exit();
}

// Add user
if (isset($_POST['add_user'])) {
  $username = $_POST['username'];
  $email = $_POST['email'];
  $role = $_POST['role'];
  $phone = $_POST['phone'];
  $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

  $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
  $check->bind_param("s", $email);
  $check->execute(); $check->store_result();

  if ($check->num_rows > 0) {
    echo "<script>alert('Email already exists!');</script>";
  } else {
    $stmt = $conn->prepare("INSERT INTO users (username, email, role, phone, password) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $username, $email, $role, $phone, $password);
    $stmt->execute();
  }
}

// Edit user
if (isset($_POST['edit_user'])) {
  $id = $_POST['user_id'];
  $username = $_POST['username'];
  $email = $_POST['email'];
  $role = $_POST['role'];
  $phone = $_POST['phone'];
  $new_password = $_POST['password'];

  if (!empty($new_password)) {
    $hashed = password_hash($new_password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=?, phone=?, password=? WHERE id=?");
    $stmt->bind_param("sssssi", $username, $email, $role, $phone, $hashed, $id);
  } else {
    $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=?, phone=? WHERE id=?");
    $stmt->bind_param("ssssi", $username, $email, $role, $phone, $id);
  }
  $stmt->execute();
}

// Delete user
if (isset($_GET['delete'])) {
  $id = $_GET['delete'];
  $conn->query("DELETE FROM users WHERE id=$id");
}

// Excel Import
if (isset($_FILES['excel_file']['name']) && $_FILES['excel_file']['tmp_name']) {
  require 'vendor/autoload.php';
  $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($_FILES['excel_file']['tmp_name']);
  $sheet = $spreadsheet->getActiveSheet()->toArray();

  $added = 0; $skipped = 0; $duplicateEmails = [];

  for ($i = 1; $i < count($sheet); $i++) {
    $username = $sheet[$i][0];
    $email = $sheet[$i][1];
    $role = $sheet[$i][2];
    $rawPassword = $sheet[$i][3];
    $phone = $sheet[$i][4];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
    if (!in_array(strtolower($role), ['admin', 'member', 'guest'])) { $skipped++; continue; }

    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $email);
    $check->execute(); $check->store_result();

    if ($check->num_rows > 0) {
      $skipped++; $duplicateEmails[] = htmlspecialchars($email);
    } else {
      $password = password_hash($rawPassword, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("INSERT INTO users (username, email, role, phone, password) VALUES (?, ?, ?, ?, ?)");
      $stmt->bind_param("sssss", $username, $email, $role, $phone, $password);
      $stmt->execute(); $added++;
    }
  }

  $_SESSION['import_feedback'] = [
    'added' => $added,
    'skipped' => $skipped,
    'duplicates' => $duplicateEmails
  ];
  header("Location: admin-dashboard.php"); exit();
}

// AJAX fetch
if (isset($_GET['action']) && $_GET['action'] === 'fetch') {
  header('Content-Type: application/json');
  $users = $conn->query("SELECT * FROM users");
  $results = $conn->query("SELECT * FROM quiz_results WHERE user_email IN (SELECT email FROM users WHERE role='guest')");
  $data = ['users' => [], 'results' => []];
  while ($row = $users->fetch_assoc()) $data['users'][] = $row;
  while ($res = $results->fetch_assoc()) $data['results'][] = $res;
  echo json_encode($data); exit();
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Admin Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <script src="https://cdn.sheetjs.com/xlsx-latest/package/dist/xlsx.full.min.js"></script>
  <style>
  /* Universal Reset */
  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }

  body {
    font-family: 'Roboto', sans-serif;
    background-color: #eaf0f6;
    color: #333;
    padding: 40px;
    line-height: 1.6;
  }

  h2, h3 {
    color: #2c3e50;
    margin-bottom: 20px;
  }

  input, select, button {
    padding: 10px 14px;
    margin: 10px 5px 20px 0;
    width: 240px;
    border-radius: 8px;
    border: 1px solid #ccc;
    font-size: 15px;
    transition: all 0.3s ease;
  }

  input:focus, select:focus {
    border-color: #3498db;
    outline: none;
    box-shadow: 0 0 4px rgba(52, 152, 219, 0.4);
  }

  button {
    background-color: #3498db;
    color: #fff;
    border: none;
    cursor: pointer;
    font-weight: bold;
  }

  button:hover {
    background-color: #2980b9;
  }

  .form-section {
    background: #ffffff;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
    margin-bottom: 30px;
  }

  .feedback-box {
    background-color: #ffe8e8;
    border-left: 5px solid #e74c3c;
    padding: 16px 20px;
    border-radius: 8px;
    color: #c0392b;
    margin-bottom: 20px;
    font-weight: 500;
  }

  table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 25px;
    background-color: white;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.05);
  }

  th, td {
    padding: 14px 18px;
    border-bottom: 1px solid #ddd;
    text-align: left;
  }

  th {
    background-color: #f9fbfc;
    font-weight: 600;
    color: #2c3e50;
  }

  tr:hover {
    background-color: #f4f9ff;
  }

  .actions a {
    margin-right: 12px;
    color: #e74c3c;
    font-weight: 600;
    text-decoration: none;
    transition: color 0.2s ease;
  }

  .actions a:hover {
    color: #c0392b;
    text-decoration: underline;
  }
/* Sidebar */
.sidebar {
  width: 240px;
  height: 100vh;
  position: fixed;
  left: 0;
  top: 0;
  background: linear-gradient(180deg, #2c3e50, #34495e);
  color: #ecf0f1;
  padding-top: 50px;
  box-shadow: 3px 0 10px rgba(0, 0, 0, 0.15);
  border-right: 1px solid #1f2d3a;
}

.sidebar h3 {
  text-align: center;
  font-size: 22px;
  font-weight: 700;
  margin-bottom: 35px;
  letter-spacing: 1px;
  color: #ffffff;
}

.sidebar nav {
  display: flex;
  flex-direction: column;
  padding: 0 20px;
}

.sidebar nav a {
  display: block;
  color: #ecf0f1;
  text-decoration: none;
  font-size: 16px;
  padding: 12px 18px;
  margin-bottom: 12px;
  border-radius: 8px;
  transition: background 0.3s ease, transform 0.2s;
}

.sidebar nav a:hover {
  background-color: #3c5a75;
  transform: translateX(5px);
}

.sidebar nav a.active {
  background-color: #1abc9c;
  color: #fff;
  font-weight: bold;
}

.logout-btn {
  margin: 20px auto;
  width: 90%;
  padding: 12px;
  background-color: #e74c3c;
  border: none;
  border-radius: 8px;
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  transition: background 0.3s ease;
}

.logout-btn:hover {
  background-color: #c0392b;
}


</style>

</head>
<body>
<div style="display: flex;">



  <!-- Top Right Logo -->
<div style="position: fixed; top: 10px; right: 20px; background-repeat: no-repeat;">
  <img src="D'Vine Healthcare - Logo - Final-03.png" alt="Logo" style="height: 50px;">
</div>





<!-- Edit User Modal -->
<div id="editModal" style="display:none; position:fixed; top:10%; left:50%; transform:translateX(-50%); background:#fff; padding:30px; border-radius:10px; box-shadow:0 0 10px rgba(0,0,0,0.2); z-index:1000;">
  <h3>Edit User</h3>
  <form method="POST">
    <input type="hidden" name="user_id" id="edit_id">
    <input type="text" name="username" id="edit_username" placeholder="Username" required>
    <input type="email" name="email" id="edit_email" placeholder="Email" required>
    <input type="text" name="phone" id="edit_phone" placeholder="Phone" required>
    <select name="role" id="edit_role" required>
      <option value="admin">Admin</option>
      <option value="member">Member</option>
      <option value="guest">Guest</option>
    </select>
    <input type="text" name="password" placeholder="New Password (leave blank to keep old)">
    <br>
    <button type="submit" name="edit_user">Update</button>
    <button type="button" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
  </form>
</div>


<!-- Sidebar -->
<div class="sidebar">
  <h3>D'vine Panel</h3>
  <nav>
    <a href="#user-management">👤 User Management</a>
    <a href="#quiz-results">📝 Quiz Results</a>
    <form method="POST">
      <button type="submit" name="logout" class="logout-btn">Logout</button>
    </form>
  </nav>
</div>


  <!-- Main Content Wrapper -->
  <div style="margin-left: 240px; width: calc(100% - 240px); padding: 40px;">

  

    <h2>Welcome to D'vine Admin</h2>

    <?php if (isset($_SESSION['import_feedback'])): $f = $_SESSION['import_feedback']; unset($_SESSION['import_feedback']); ?>
    <div class="feedback-box">
      <strong>Import Summary:</strong><br>
      ✅ <?= $f['added'] ?> users added<br>
      ⚠️ <?= $f['skipped'] ?> duplicates skipped<br>
      <?php if (!empty($f['duplicates'])): ?>
        <div><strong>Duplicate Emails:</strong>
          <ul style="margin:5px 0 0 20px; color:red;">
            <?php foreach ($f['duplicates'] as $dup): ?><li><?= $dup ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    

    <!-- USER MANAGEMENT SECTION -->
    <div id="user-management" class="form-section">
      <h3>Add New User</h3>
      <form method="POST">
        <input type="text" name="username" placeholder="Username" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="text" name="phone" placeholder="Phone Number" required>
        <select name="role" required>
          <option value="">Role</option><option value="admin">Admin</option><option value="member">Member</option><option value="guest">Guest</option>
        </select>
        <input type="text" name="password" placeholder="Password" required>
        <button type="submit" name="add_user">Add User</button>
      </form>
    </div>

    <div class="form-section">
      <h3>Import Users from Excel</h3>
      <form method="POST" enctype="multipart/form-data">
        <input type="file" name="excel_file" accept=".xlsx,.xls" required>
        <button type="submit">Import Excel</button>
        <button type="button" onclick="downloadSample()">Download Sample Sheet</button>
      </form>
    </div>

    <div class="form-section">
      <h3>User List</h3>
      <select id="roleFilter" onchange="filterTable()">
        <option value="all">All Roles</option><option value="admin">Admin</option><option value="member">Member</option><option value="guest">Guest</option>
      </select>
      <input type="text" id="search" placeholder="Search..." onkeyup="filterTable()">
      <br>
      <button onclick="exportFilteredTableToExcel('userTable', 'FilteredUsers')">Export Filtered Users</button>
      <button onclick="exportAllTableToExcel('userTable', 'AllUsers')">Export All Users</button>

      <table id="userTable">
        <thead>
          <tr><th>ID</th><th>Username</th><th>Email</th><th>Phone</th><th>Role</th><th>Actions</th></tr>
        </thead>
        <tbody id="userTableBody"></tbody>
      </table>
    </div>

    <!-- QUIZ RESULTS SECTION -->
    <div id="quiz-results" class="form-section">
      <h3>Guest Quiz Results</h3>
      <input type="text" id="quizSearch" placeholder="Search by email or value..." onkeyup="filterQuizTable()">
      <br>
      <label>Test 1 Min %: <input type="number" id="minPercent1" oninput="filterQuizTable()"></label>
      <label>Test 1 Max %: <input type="number" id="maxPercent1" oninput="filterQuizTable()"></label>
      <br>
      <label>Test 2 Min %: <input type="number" id="minPercent2" oninput="filterQuizTable()"></label>
      <label>Test 2 Max %: <input type="number" id="maxPercent2" oninput="filterQuizTable()"></label>
      <br>

      <button onclick="exportFilteredTableToExcel('quizTable', 'FilteredQuizResults')">Export Filtered Results</button>
      <button onclick="exportAllTableToExcel('quizTable', 'AllQuizResults')">Export All Results</button>

      <table id="quizTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>Email</th>
            <th>Test 1 Score</th>
            <th>Test 1 %</th>
            <th>Test 1 Date</th>
            <th>Test 2 Score</th>
            <th>Test 2 %</th>
            <th>Test 2 Date</th>
          </tr>
        </thead>
        <tbody id="quizTableBody"></tbody>
      </table>
    </div>
  </div>
</div>

<script>
function cleanTableForExport(tableId, filteredOnly = false) {
  const table = document.getElementById(tableId);
  const exportTable = document.createElement("table");
  const thead = table.querySelector("thead").cloneNode(true);
  const tbody = document.createElement("tbody");

  table.querySelectorAll("tbody tr").forEach(row => {
    if (!filteredOnly || row.style.display !== "none") {
      tbody.appendChild(row.cloneNode(true));
    }
  });

  exportTable.appendChild(thead);
  exportTable.appendChild(tbody);
  return exportTable;
}

function exportFilteredTableToExcel(tableId, sheetName) {
  const exportTable = cleanTableForExport(tableId, true);
  const wb = XLSX.utils.table_to_book(exportTable, { sheet: sheetName });
  XLSX.writeFile(wb, sheetName + ".xlsx");
}

function exportAllTableToExcel(tableId, sheetName) {
  const exportTable = cleanTableForExport(tableId, false);
  const wb = XLSX.utils.table_to_book(exportTable, { sheet: sheetName });
  XLSX.writeFile(wb, sheetName + ".xlsx");
}

function filterTable() {
  const role = document.getElementById("roleFilter").value;
  const search = document.getElementById("search").value.toLowerCase();
  const rows = document.querySelectorAll("#userTableBody tr");
  rows.forEach(row => {
    const matchRole = (role === "all" || row.cells[4].textContent.toLowerCase() === role);
    const matchSearch = row.innerText.toLowerCase().includes(search);
    row.style.display = (matchRole && matchSearch) ? "" : "none";
  });
}

function editUser(id, username, email, phone, role) {
  document.getElementById('edit_id').value = id;
  document.getElementById('edit_username').value = username;
  document.getElementById('edit_email').value = email;
  document.getElementById('edit_phone').value = phone;
  document.getElementById('edit_role').value = role;
  document.getElementById('editModal').style.display = 'block';
}

function loadData() {
  fetch('admin-dashboard.php?action=fetch')
    .then(res => res.json())
    .then(data => {
      const userBody = document.getElementById("userTableBody");
      const quizBody = document.getElementById("quizTableBody");
      userBody.innerHTML = ""; quizBody.innerHTML = "";
      data.users.forEach(u => {
        userBody.innerHTML += `
          <tr>
            <td>${u.id}</td>
            <td>${u.username}</td>
            <td>${u.email}</td>
            <td>${u.phone}</td>
            <td>${u.role}</td>
            <td class="actions">
              <a href="#" onclick="editUser(${u.id}, '${u.username.replace(/'/g, "\\'")}', '${u.email}', '${u.phone}', '${u.role}')">Edit</a> |
              <a href="?delete=${u.id}" onclick="return confirm('Delete user?')">Delete</a>
            </td>
          </tr>`;
      });
      data.results.forEach(r => {
  quizBody.innerHTML += `
    <tr>
      <td>${r.id}</td> <!-- Add this for ID -->
      <td>${r.user_email}</td>
      <td>${r.test1_score ?? ''}</td>
      <td>${r.test1_percentage ?? ''}%</td>
      <td>${r.test1_date ?? ''}</td>
      <td>${r.test2_score ?? ''}</td>
      <td>${r.test2_percentage ?? ''}%</td>
      <td>${r.test2_date ?? ''}</td>
    </tr>`;
});

    });
}

function downloadSample() {
  const wb = XLSX.utils.book_new();
  const ws = XLSX.utils.aoa_to_sheet([
    ["Username", "Email", "Role", "Password", "Phone"],
    ["", "", "", "", ""]
  ]);
  XLSX.utils.book_append_sheet(wb, ws, "Sample");
  XLSX.writeFile(wb, "sample_users.xlsx");
}

function filterQuizTable() {
  const input = document.getElementById("quizSearch").value.toLowerCase();
  const rows = document.querySelectorAll("#quizTableBody tr");

  rows.forEach(row => {
    const text = row.innerText.toLowerCase();
    row.style.display = text.includes(input) ? "" : "none";
  });
}


function filterQuizTable() {
  const search = document.getElementById("quizSearch").value.toLowerCase();

  const min1 = parseFloat(document.getElementById("minPercent1").value);
  const max1 = parseFloat(document.getElementById("maxPercent1").value);
  const min2 = parseFloat(document.getElementById("minPercent2").value);
  const max2 = parseFloat(document.getElementById("maxPercent2").value);

  const rows = document.querySelectorAll("#quizTableBody tr");

  rows.forEach(row => {
    const cells = row.querySelectorAll("td");
    const rowText = row.innerText.toLowerCase();

    const t1 = parseFloat(cells[2].innerText.replace('%', '')) || 0;
    const t2 = parseFloat(cells[5].innerText.replace('%', '')) || 0;

    const matchSearch = rowText.includes(search);
    const matchMin1 = isNaN(min1) || t1 >= min1;
    const matchMax1 = isNaN(max1) || t1 <= max1;
    const matchMin2 = isNaN(min2) || t2 >= min2;
    const matchMax2 = isNaN(max2) || t2 <= max2;

    const matchAll = matchSearch && matchMin1 && matchMax1 && matchMin2 && matchMax2;
    row.style.display = matchAll ? "" : "none";
  });
}



setInterval(loadData, 5000);
window.onload = loadData;
</script>
</body>
</html>
