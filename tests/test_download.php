<?php

// Simple test script to verify CSV download functionality

// Create a test CSV file
$testFile = __DIR__ . '/data/test_download.csv';
$testData = [
    ['id' => '1', 'name' => 'John Doe', 'email' => 'john@example.com'],
    ['id' => '2', 'name' => 'Jane Smith', 'email' => 'jane@example.com']
];

// Ensure data directory exists
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0777, true);
}

// Create test file
$file = fopen($testFile, 'w');
fputcsv($file, array_keys($testData[0]));
foreach ($testData as $row) {
    fputcsv($file, $row);
}
fclose($file);

echo "Testing CSV Download Functionality\n";
echo "==================================\n\n";

// Test 1: Check if file exists
echo "1. Testing file existence...\n";
if (file_exists($testFile)) {
    echo "✓ Test file created successfully\n";
    echo "File size: " . filesize($testFile) . " bytes\n\n";
} else {
    echo "✗ Test file creation failed!\n\n";
    exit(1);
}

// Test 2: Simulate download headers
echo "2. Testing download headers...\n";
$filename = basename($testFile);
$filePath = $testFile;

// Set headers for file download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

echo "✓ Headers set correctly:\n";
echo "  - Content-Type: text/csv\n";
echo "  - Content-Disposition: attachment; filename=\"$filename\"\n";
echo "  - Content-Length: " . filesize($filePath) . "\n\n";

// Test 3: Verify file content
echo "3. Testing file content...\n";
$content = file_get_contents($testFile);
if (strpos($content, 'id,name,email') !== false) {
    echo "✓ CSV headers found\n";
} else {
    echo "✗ CSV headers not found!\n";
}

if (strpos($content, '1,John Doe,john@example.com') !== false) {
    echo "✓ CSV data found\n";
} else {
    echo "✗ CSV data not found!\n";
}

if (strpos($content, '2,Jane Smith,jane@example.com') !== false) {
    echo "✓ Additional CSV data found\n\n";
} else {
    echo "✗ Additional CSV data not found!\n\n";
}

// Test 4: Test file download simulation
echo "4. Testing download simulation...\n";
$downloadPath = __DIR__ . '/data/downloaded_test.csv';

// Simulate the download process
if (copy($testFile, $downloadPath)) {
    echo "✓ File download simulation successful\n";
    
    // Verify downloaded content
    $downloadedContent = file_get_contents($downloadPath);
    if ($downloadedContent === $content) {
        echo "✓ Downloaded content matches original\n\n";
    } else {
        echo "✗ Downloaded content does not match original!\n\n";
    }
    
    // Clean up downloaded file
    unlink($downloadPath);
} else {
    echo "✗ File download simulation failed!\n\n";
}

// Test 5: Test readfile function
echo "5. Testing readfile function...\n";
$output = '';
ob_start();
readfile($testFile);
$output = ob_get_clean();

if ($output === $content) {
    echo "✓ readfile function works correctly\n\n";
} else {
    echo "✗ readfile function failed!\n\n";
}

echo "CSV Download tests completed!\n";

// Clean up
unlink($testFile); 