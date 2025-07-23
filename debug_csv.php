<?php
/**
 * CSV Debug Script
 * 
 * This script helps debug CSV file issues by analyzing the file line by line
 * and identifying problematic records.
 */

require "config.php";

// Configuration
$filename = 'demo.csv'; // Change this to your CSV file
$dataDir = DATA_DIR;
$filePath = $dataDir . '/' . $filename;

echo "CSV Debug Analysis for: {$filename}\n";
echo "=====================================\n\n";

if (!file_exists($filePath)) {
    die("Error: File not found: {$filePath}\n");
}

$file = fopen($filePath, 'r');
if ($file === false) {
    die("Error: Could not open file: {$filePath}\n");
}

// Read headers
$headers = fgetcsv($file);
if (!$headers) {
    die("Error: No headers found in CSV file\n");
}

echo "Headers (" . count($headers) . " columns):\n";
echo implode(', ', $headers) . "\n\n";

$lineNumber = 1; // Header line
$validLines = 0;
$invalidLines = [];
$totalLines = 0;

echo "Analyzing data lines...\n";

while (($row = fgetcsv($file)) !== false) {
    $lineNumber++;
    $totalLines++;
    
    if (count($row) === count($headers)) {
        $validLines++;
    } else {
        $invalidLines[] = [
            'line' => $lineNumber,
            'expected' => count($headers),
            'actual' => count($row),
            'content' => $row
        ];
        
        echo "MISMATCHED LINE {$lineNumber}: Expected " . count($headers) . " columns, got " . count($row) . "\n";
        echo "  Original content: " . implode(', ', $row) . "\n";
        
        if (count($row) < count($headers)) {
            $missing = array_slice($headers, count($row));
            echo "  Missing columns: " . implode(', ', $missing) . "\n";
            echo "  Would be filled with: null values\n";
        } else {
            $extra = array_slice($row, count($headers));
            echo "  Extra columns: " . implode(', ', $extra) . "\n";
            echo "  Would be truncated\n";
        }
        echo "\n";
    }
}

fclose($file);

echo "=====================================\n";
echo "SUMMARY:\n";
echo "Total lines (including header): {$lineNumber}\n";
echo "Data lines: {$totalLines}\n";
echo "Perfect records: {$validLines}\n";
echo "Mismatched records: " . count($invalidLines) . "\n";
echo "Note: The API now fixes mismatched records by filling missing columns with null or truncating extra columns.\n\n";

if (count($invalidLines) > 0) {
    echo "MISMATCHED LINES DETAILS:\n";
    echo "========================\n";
    foreach ($invalidLines as $invalid) {
        echo "Line {$invalid['line']}: Expected {$invalid['expected']} columns, got {$invalid['actual']}\n";
        echo "  Raw content: " . json_encode($invalid['content']) . "\n\n";
    }
    
    echo "To debug a specific line using the API:\n";
    echo "GET /api/csv/{$filename}/debug/{line_number}\n\n";
    
    echo "Example API calls:\n";
    foreach (array_slice($invalidLines, 0, 5) as $invalid) { // Show first 5
        echo "curl -H 'Authorization: Bearer YOUR_TOKEN' 'http://your-domain/api.php/api/csv/{$filename}/debug/{$invalid['line']}'\n";
    }
} 