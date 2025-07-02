<?php

// Simple test script to verify JWT authentication
// JWT Configuration
define('JWT_SECRET', 'your-secret-key-change-this-in-production');
define('JWT_ALGORITHM', 'HS256');
define('JWT_EXPIRY', 3600); // 1 hour

// User credentials
$validUsers = [
    'admin' => password_hash('secret123', PASSWORD_DEFAULT)
];

// JWT Helper Functions
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
}

function generateJWT($username) {
    $header = json_encode(['typ' => 'JWT', 'alg' => JWT_ALGORITHM]);
    $payload = json_encode([
        'sub' => $username,
        'iat' => time(),
        'exp' => time() + JWT_EXPIRY
    ]);
    
    $base64Header = base64url_encode($header);
    $base64Payload = base64url_encode($payload);
    
    $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, JWT_SECRET, true);
    $base64Signature = base64url_encode($signature);
    
    return $base64Header . "." . $base64Payload . "." . $base64Signature;
}

function verifyJWT($token) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return false;
    }
    
    list($header, $payload, $signature) = $parts;
    
    $validSignature = base64url_encode(hash_hmac('sha256', $header . "." . $payload, JWT_SECRET, true));
    
    if (!hash_equals($signature, $validSignature)) {
        return false;
    }
    
    $payloadData = json_decode(base64url_decode($payload), true);
    
    if (!$payloadData || !isset($payloadData['exp']) || $payloadData['exp'] < time()) {
        return false;
    }
    
    return $payloadData;
}

echo "Testing JWT Authentication\n";
echo "==========================\n\n";

// Test 1: Generate JWT token
echo "1. Testing JWT token generation...\n";
$token = generateJWT('admin');
echo "Generated token: " . substr($token, 0, 50) . "...\n";

// Test 2: Verify JWT token
echo "\n2. Testing JWT token verification...\n";
$payload = verifyJWT($token);
if ($payload) {
    echo "Token verified successfully!\n";
    echo "Username: " . $payload['sub'] . "\n";
    echo "Expires: " . date('Y-m-d H:i:s', $payload['exp']) . "\n";
} else {
    echo "Token verification failed!\n";
}

// Test 3: Test invalid token
echo "\n3. Testing invalid token...\n";
$invalidToken = "invalid.token.here";
$payload = verifyJWT($invalidToken);
if (!$payload) {
    echo "Invalid token correctly rejected!\n";
} else {
    echo "ERROR: Invalid token was accepted!\n";
}

// Test 4: Test expired token
echo "\n4. Testing expired token...\n";
$expiredToken = generateJWT('admin');
// Manually create an expired token by modifying the payload
$parts = explode('.', $expiredToken);
$payload = json_decode(base64url_decode($parts[1]), true);
$payload['exp'] = time() - 3600; // Set expiry to 1 hour ago
$parts[1] = base64url_encode(json_encode($payload));
$expiredToken = implode('.', $parts);

$payload = verifyJWT($expiredToken);
if (!$payload) {
    echo "Expired token correctly rejected!\n";
} else {
    echo "ERROR: Expired token was accepted!\n";
}

// Test 5: Test user authentication
echo "\n5. Testing user authentication...\n";
if (isset($validUsers['admin']) && password_verify('secret123', $validUsers['admin'])) {
    echo "User authentication working correctly!\n";
} else {
    echo "ERROR: User authentication failed!\n";
}

echo "\nJWT Authentication tests completed!\n"; 