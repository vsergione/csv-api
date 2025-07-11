<?php

// Simple test script to verify partial update functionality
// Include only the necessary classes and functions

// Input sanitization functions
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    if (!is_string($input)) {
        return $input;
    }
    // Remove HTML tags and encode special characters
    return htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8');
}

class CsvHandler {
    private string $filePath;
    private array $headers;
    private array $data;
    private string $resourceType;

    public function __construct(string $filePath) {
        $this->filePath = $filePath;
        $this->resourceType = pathinfo($filePath, PATHINFO_FILENAME);
        $this->loadData();
    }

    private function loadData(): void {
        if (!file_exists($this->filePath)) {
            throw new RuntimeException("CSV file not found: {$this->filePath}");
        }

        $file = fopen($this->filePath, 'r');
        if ($file === false) {
            throw new RuntimeException("Could not open file: {$this->filePath}");
        }

        // Read headers
        $csvFile = fgetcsv($file);
        if(!$csvFile) {
            fclose($file);
            throw new RuntimeException("Invalid CSV file: No headers found");
        }
        $this->headers = array_map('sanitizeInput', $csvFile);
        if ($this->headers === false) {
            fclose($file);
            throw new RuntimeException("Invalid CSV file: No headers found");
        }

        // Read data
        $this->data = [];
        while (($row = fgetcsv($file)) !== false) {
            if (count($row) === count($this->headers)) {
                $this->data[] = array_combine($this->headers, array_map('sanitizeInput', $row));
            }
        }

        fclose($file);
    }

    private function saveData(): void {
        $file = fopen($this->filePath, 'w');
        if ($file === false) {
            throw new RuntimeException("Could not open file for writing: {$this->filePath}");
        }

        // Write headers
        fputcsv($file, $this->headers);

        // Write data
        foreach ($this->data as $row) {
            fputcsv($file, array_values($row));
        }

        fclose($file);
    }

    private function formatResourceObject(array $row, int $index): array {
        return [
            'type' => $this->resourceType,
            'id' => (string)$index,
            'attributes' => $row
        ];
    }

    public function getById(int $id): ?array {
        if (!isset($this->data[$id])) {
            return null;
        }
        return [
            'data' => $this->formatResourceObject($this->data[$id], $id)
        ];
    }

    public function update(int $id, array $attributes): bool {
        if (!isset($this->data[$id])) {
            return false;
        }

        // Sanitize input attributes
        $attributes = sanitizeInput($attributes);

        // Validate that all provided attributes correspond to valid headers
        foreach ($attributes as $key => $value) {
            if (!in_array($key, $this->headers)) {
                throw new InvalidArgumentException("Invalid field: {$key}");
            }
        }

        // Merge provided attributes with existing data (partial update)
        $this->data[$id] = array_merge($this->data[$id], $attributes);
        $this->saveData();
        return true;
    }
}

// Create a test CSV file
$testFile = __DIR__ . '/data/test_partial.csv';
$testData = [
    ['id' => '1', 'name' => 'John Doe', 'email' => 'john@example.com', 'age' => '30'],
    ['id' => '2', 'name' => 'Jane Smith', 'email' => 'jane@example.com', 'age' => '25']
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

echo "Testing Partial Update Functionality\n";
echo "===================================\n\n";

// Create CSV handler instance
$csvHandler = new CsvHandler($testFile);

// Test 1: Get original record
echo "1. Testing get original record...\n";
$originalRecord = $csvHandler->getById(0);
echo "Original record: " . json_encode($originalRecord['data']['attributes'], JSON_PRETTY_PRINT) . "\n\n";

// Test 2: Partial update - only update name
echo "2. Testing partial update (name only)...\n";
$partialAttributes = ['name' => 'John Updated'];
$success = $csvHandler->update(0, $partialAttributes);
if ($success) {
    $updatedRecord = $csvHandler->getById(0);
    echo "Updated record: " . json_encode($updatedRecord['data']['attributes'], JSON_PRETTY_PRINT) . "\n";
    echo "✓ Name updated: " . ($updatedRecord['data']['attributes']['name'] === 'John Updated' ? 'YES' : 'NO') . "\n";
    echo "✓ Email unchanged: " . ($updatedRecord['data']['attributes']['email'] === 'john@example.com' ? 'YES' : 'NO') . "\n";
    echo "✓ Age unchanged: " . ($updatedRecord['data']['attributes']['age'] === '30' ? 'YES' : 'NO') . "\n\n";
} else {
    echo "✗ Update failed!\n\n";
}

// Test 3: Partial update - only update email
echo "3. Testing partial update (email only)...\n";
$partialAttributes = ['email' => 'john.new@example.com'];
$success = $csvHandler->update(0, $partialAttributes);
if ($success) {
    $updatedRecord = $csvHandler->getById(0);
    echo "Updated record: " . json_encode($updatedRecord['data']['attributes'], JSON_PRETTY_PRINT) . "\n";
    echo "✓ Name unchanged: " . ($updatedRecord['data']['attributes']['name'] === 'John Updated' ? 'YES' : 'NO') . "\n";
    echo "✓ Email updated: " . ($updatedRecord['data']['attributes']['email'] === 'john.new@example.com' ? 'YES' : 'NO') . "\n";
    echo "✓ Age unchanged: " . ($updatedRecord['data']['attributes']['age'] === '30' ? 'YES' : 'NO') . "\n\n";
} else {
    echo "✗ Update failed!\n\n";
}

// Test 4: Test invalid field
echo "4. Testing invalid field validation...\n";
try {
    $invalidAttributes = ['invalid_field' => 'This should fail'];
    $csvHandler->update(0, $invalidAttributes);
    echo "✗ Invalid field was accepted!\n\n";
} catch (InvalidArgumentException $e) {
    echo "✓ Invalid field correctly rejected: " . $e->getMessage() . "\n\n";
}

// Test 5: Test multiple fields update
echo "5. Testing multiple fields update...\n";
$multipleAttributes = ['name' => 'John Final', 'age' => '35'];
$success = $csvHandler->update(0, $multipleAttributes);
if ($success) {
    $updatedRecord = $csvHandler->getById(0);
    echo "Updated record: " . json_encode($updatedRecord['data']['attributes'], JSON_PRETTY_PRINT) . "\n";
    echo "✓ Name updated: " . ($updatedRecord['data']['attributes']['name'] === 'John Final' ? 'YES' : 'NO') . "\n";
    echo "✓ Email unchanged: " . ($updatedRecord['data']['attributes']['email'] === 'john.new@example.com' ? 'YES' : 'NO') . "\n";
    echo "✓ Age updated: " . ($updatedRecord['data']['attributes']['age'] === '35' ? 'YES' : 'NO') . "\n\n";
} else {
    echo "✗ Update failed!\n\n";
}

echo "Partial Update tests completed!\n";

// Clean up
unlink($testFile); 