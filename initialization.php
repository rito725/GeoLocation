<?php
// Database Configuration
$host = 'localhost:3307';
$user = 'root';
$password = '';
$database = 'MarketListings';

// Google Maps API Key
$GOOGLE_API_KEY = 'AIzaSyANRs0V5yc4UYmuJMilELDOHyKLGI7ayK4';

// Increase PHP execution time
ini_set('max_execution_time', 10000); // 5 minutes

// Connect to the database
$connection = new mysqli($host, $user, $password, $database);

if ($connection->connect_error) {
    die("Database connection failed: " . $connection->connect_error);
}

// Function to fetch coordinates using cURL
function fetchCoordinates($query, $apiKey) {
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($query) . "&key=" . $apiKey;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);

    if (isset($data['results'][0]['geometry']['location'])) {
        $location = $data['results'][0]['geometry']['location'];
        return ['latitude' => $location['lat'], 'longitude' => $location['lng']];
    }
    return ['latitude' => null, 'longitude' => null];
}

// Function to extract ZIP code from address
function extractZipCode($address) {
    $zipCodeRegex = '/\b\d{5}(?:-\d{4})?\b|\b\d{6}\b/';
    if (preg_match($zipCodeRegex, $address, $matches)) {
        return $matches[0];
    }
    return null;
}

// Function to update coordinates in the database
function updateCoordinatesInDatabase($connection, $id, $latitude, $longitude) {
    $stmt = $connection->prepare("UPDATE customers SET latitude = ?, longitude = ? WHERE id = ?");
    $stmt->bind_param("ddi", $latitude, $longitude, $id);
    $stmt->execute();
    $stmt->close();
}

// Batch processing
function initializeCoordinates($connection, $apiKey) {
    $limit = 100; // Process 100 records at a time
    $offset = 0;  // Start offset
    $hasMoreRecords = true;

    echo "Initializing coordinates for all customers...\n";

    while ($hasMoreRecords) {
        $query = "SELECT id, address, latitude, longitude FROM customers WHERE latitude IS NULL OR longitude IS NULL LIMIT $offset, $limit";
        $result = $connection->query($query);

        if ($result->num_rows > 0) {
            while ($customer = $result->fetch_assoc()) {
                $id = $customer['id'];
                $address = $customer['address'];

                // Try fetching coordinates using the full address
                $coords = fetchCoordinates($address, $apiKey);

                // If address-based coordinates are not found, try using the ZIP code
                if (is_null($coords['latitude']) || is_null($coords['longitude'])) {
                    $zipCode = extractZipCode($address);
                    if ($zipCode) {
                        echo "Attempting to fetch coordinates using ZIP code: $zipCode\n";
                        $coords = fetchCoordinates($zipCode, $apiKey);
                    }
                }

                // Update the database if coordinates are found
                if (!is_null($coords['latitude']) && !is_null($coords['longitude'])) {
                    echo "Updating coordinates for customer ID $id\n";
                    updateCoordinatesInDatabase($connection, $id, $coords['latitude'], $coords['longitude']);
                } else {
                    echo "Unable to fetch coordinates for customer ID $id\n";
                }

                // Introduce a delay to prevent API rate limiting
                usleep(200000); // 200 milliseconds
            }

            $offset += $limit;
        } else {
            $hasMoreRecords = false; // No more records to process
        }
    }
}

// Start initialization process
initializeCoordinates($connection, $GOOGLE_API_KEY);

// Fetch all customer data including coordinates
$query = "SELECT * FROM customers";
$result = $connection->query($query);

$customers = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Close the database connection
$connection->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Customer Data</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f4f4f4;
        }
        .location-input, .filter-buttons {
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <h1>Customer Data with Coordinates</h1>
    
    

    <table>
        <thead>
            <tr>
                <?php if (!empty($customers)) { ?>
                    <?php foreach (array_keys($customers[0]) as $column) { ?>
                        <th><?= htmlspecialchars($column) ?></th>
                    <?php } ?>
                <?php } else { ?>
                    <th>No Data Available</th>
                <?php } ?>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($customers)) { ?>
                <?php foreach ($customers as $customer) { ?>
                    <tr>
                        <?php foreach ($customer as $value) { ?>
                            <td><?= htmlspecialchars($value) ?></td>
                        <?php } ?>
                    </tr>
                <?php } ?>
            <?php } else { ?>
                <tr>
                    <td colspan="<?= isset($customers[0]) ? count($customers[0]) : 1 ?>">No data found</td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</body>
</html>
