<?php
session_start();

// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// do composer installation first - composer require phpoffice/phpspreadsheet

// Database connection
try {
    $db = new PDO('mysql:host=localhost;dbname=realestate;charset=utf8', 'username', 'password');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Enable exceptions
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Include libraries for reading Excel files
require 'vendor/autoload.php'; // Ensure you have PhpSpreadsheet installed

use PhpOffice\PhpSpreadsheet\IOFactory;

// Step 1: Display the upload form
if ($_SERVER['REQUEST_METHOD'] == 'GET' || !isset($_POST['step'])) {
    // Get list of tables from the database
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    ?>
    <!DOCTYPE html>
    <html lang="he">
    <head>
        <meta charset="UTF-8">
        <title>Import Data</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="container mt-5" dir="rtl">
        <h1>ייבוא נתונים</h1>
        <form action="import.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="step" value="1">
            <div class="mb-3">
                <label class="form-label">בחר טבלה לייבוא</label>
                <select name="table_name" class="form-select" required>
                    <?php foreach ($tables as $table): ?>
                        <option value="<?= htmlspecialchars($table) ?>"><?= htmlspecialchars($table) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">בחר קובץ (CSV או Excel)</label>
                <input type="file" name="data_file" class="form-control" accept=".csv, .xls, .xlsx" required>
            </div>
            <button type="submit" class="btn btn-primary">העלה קובץ והמשך</button>
        </form>
    </body>
    </html>
    <?php
    exit();
}

// Function to read data from CSV or Excel file
function readDataFromFile($filePath, $fileType) {
    $data = [];
    if ($fileType == 'csv') {
        if (($handle = fopen($filePath, 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }
    } else {
        // Use PhpSpreadsheet to read Excel files
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        foreach ($worksheet->getRowIterator() as $row) {
            $rowData = [];
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // Include empty cells
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getValue();
            }
            $data[] = $rowData;
        }
    }
    return $data;
}

// Step 2: Display the mapping form
if ($_POST['step'] == '1') {
    // Handle file upload and table selection
    if (empty($_FILES['data_file']['tmp_name']) || empty($_POST['table_name'])) {
        die("Please select a table and a file.");
    }

    $table = $_POST['table_name'];
    $uploadedFile = $_FILES['data_file']['tmp_name'];
    $fileName = $_FILES['data_file']['name'];
    $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    // Save the uploaded file temporarily
    $tempFilePath = sys_get_temp_dir() . '/' . uniqid('import_', true) . '.' . $fileType;
    move_uploaded_file($uploadedFile, $tempFilePath);

    // Save data in session for later steps
    $_SESSION['uploaded_file'] = $tempFilePath;
    $_SESSION['file_type'] = $fileType;
    $_SESSION['table_name'] = $table;

    // Read data from the file
    $data = readDataFromFile($tempFilePath, $fileType);
    if (empty($data)) {
        die("The file is empty or cannot be read.");
    }

    // Get headers from the CSV or Excel file
    $headers = array_shift($data);

    // Save data in session
    $_SESSION['data_rows'] = $data;

    // Get column names from the table
    $stmt = $db->prepare("DESCRIBE `$table`");
    $stmt->execute();
    $table_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Remove 'id' column if present
    if (($key = array_search('id', $table_columns)) !== false) {
        unset($table_columns[$key]);
    }

    ?>
    <!DOCTYPE html>
    <html lang="he">
    <head>
        <meta charset="UTF-8">
        <title>Map Columns</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            .mapping-table {
                max-width: 600px;
                margin: 0 auto;
            }
        </style>
    </head>
    <body class="container mt-5" dir="rtl">
        <h1>מיפוי עמודות</h1>
        <form action="import.php" method="POST">
            <input type="hidden" name="step" value="2">
            <table class="table table-bordered mapping-table">
                <thead>
                    <tr>
                        <th>כותרת בקובץ</th>
                        <th>עמודה בטבלה</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($headers as $index => $header): ?>
                        <tr>
                            <td><?= htmlspecialchars($header) ?></td>
                            <td>
                                <select name="mapping[<?= $index ?>]" class="form-select">
                                    <option value="">-- אל תייבא --</option>
                                    <?php foreach ($table_columns as $column): ?>
                                        <option value="<?= htmlspecialchars($column) ?>"><?= htmlspecialchars($column) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn btn-primary">ייבא נתונים</button>
        </form>
    </body>
    </html>
    <?php
    exit();
}

// Step 3: Import the data
if ($_POST['step'] == '2') {
    // Handle data import
    $table = $_SESSION['table_name'];
    $fileType = $_SESSION['file_type'];
    $data = $_SESSION['data_rows'];
    $mapping = $_POST['mapping'];

    // Get mapped columns (exclude ones marked as 'Do not import')
    $columns = array_filter(array_values($mapping));

    // Check if at least one column is mapped
    if (empty($columns)) {
        die("You must map at least one column to import.");
    }

    // Prepare the insert query
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $query = "INSERT INTO `$table` (" . implode(',', $columns) . ") VALUES ($placeholders)";
    $stmt = $db->prepare($query);

    $rowCount = 0;
    $failedRows = [];
    try {
        $db->beginTransaction();
        foreach ($data as $rowIndex => $row) {
            // Build the values array based on the mapping
            $values = [];
            foreach ($mapping as $index => $column) {
                if (!empty($column)) {
                    $values[] = isset($row[$index]) ? $row[$index] : null;
                }
            }
            // Skip if values array is empty (no columns mapped)
            if (empty($values)) {
                continue;
            }
            try {
                $stmt->execute($values);
                $rowCount++;
            } catch (Exception $e) {
                // Add the row to the list of failed rows
                $failedRows[] = [
                    'row' => $rowIndex + 2, // +2 because of the header and zero-based index
                    'error' => $e->getMessage(),
                    'data' => $values
                ];
            }
        }
        $db->commit();
        // Clean up the session and temporary file
        unlink($_SESSION['uploaded_file']);
        unset($_SESSION['uploaded_file']);
        unset($_SESSION['file_type']);
        unset($_SESSION['table_name']);
        unset($_SESSION['data_rows']);
        ?>
        <!DOCTYPE html>
        <html lang="he">
        <head>
            <meta charset="UTF-8">
            <title>Import Successful</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="container mt-5" dir="rtl">
            <h1>ייבוא נתונים</h1>
            <div class="alert alert-success">
                יובאו בהצלחה <?= $rowCount ?> שורות לטבלה <?= htmlspecialchars($table) ?>.
            </div>
            <?php if (!empty($failedRows)): ?>
                <div class="alert alert-warning">
                    השורות הבאות נכשלו בייבוא:
                    <ul>
                        <?php foreach ($failedRows as $failedRow): ?>
                            <li>שורה <?= $failedRow['row'] ?>: <?= htmlspecialchars($failedRow['error']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <a href="import.php" class="btn btn-primary">חזור לייבוא נוסף</a>
        </body>
        </html>
        <?php
    } catch (Exception $e) {
        $db->rollBack();
        die("Error importing data: " . $e->getMessage());
    }
    exit();
}
?>
