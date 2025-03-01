<?php
require 'db.php'; // db.php provides a working $pdo PDO instance

// Detailed query for each account row using conditional age calculation.
$sql = "
  SELECT 
    account_id,
    status,
    ac_score,
    CASE 
      WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
      WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
      ELSE 0
    END AS calculated_age,
    GREATEST(
      CASE 
        WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
        WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
        ELSE 0
      END,
      ac_score
    ) AS final_value
  FROM accounts
  ORDER BY account_id
";
$stmt = $pdo->query($sql);
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Query to sum up the final values for all accounts
$totalSql = "
  SELECT SUM(
    GREATEST(
      CASE 
        WHEN status = 'suspended' THEN DATEDIFF(suspended_date, added_date)
        WHEN status = 'active' THEN DATEDIFF(CURRENT_DATE, added_date)
        ELSE 0
      END,
      ac_score
    )
  ) AS total_final
  FROM accounts
";
$stmtTotal = $pdo->query($totalSql);
$totalFinal = $stmtTotal->fetchColumn();
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Accounts Summary</title>
  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.10.24/css/jquery.dataTables.min.css">
  <!-- Optional: Bootstrap CSS for styling -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    /* Single container styling */
    .container-fluid { padding: 0; }
    .content { padding: 20px; }
  </style>
</head>
<body>
<div class="container-fluid">
  <div class="content">
    <h2>Accounts Summary</h2>
    <!-- Display the total sum of final values -->
    <p><strong>Total Sum of Final Values:</strong> <?php echo htmlspecialchars($totalFinal); ?></p>
    
    <!-- jQuery DataTable -->
    <table id="accountsTable" class="display" style="width:100%">
      <thead>
        <tr>
          <th>Account ID</th>
          <th>Status</th>
          <th>Account Score</th>
          <th>Calculated Age</th>
          <th>Final Value</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($accounts as $acc): ?>
          <tr>
            <td><?php echo htmlspecialchars($acc['account_id']); ?></td>
            <td><?php echo htmlspecialchars($acc['status']); ?></td>
            <td><?php echo htmlspecialchars($acc['ac_score']); ?></td>
            <td><?php echo htmlspecialchars($acc['calculated_age']); ?></td>
            <td><?php echo htmlspecialchars($acc['final_value']); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.10.24/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
  // Initialize DataTable with default pagination, search, and info options
  $('#accountsTable').DataTable();
});
</script>
</body>
</html>
