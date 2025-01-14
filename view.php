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

// Function to calculate distance using the Haversine formula
function calculateDistance($lat1, $lng1, $lat2, $lng2) {
    $R = 6371; // Radius of Earth in kilometers
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c; // Distance in kilometers
}

// Function to fetch coordinates using Google Maps API
function fetchCoordinates($query, $apiKey) {
    $url = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($query) . "&key=" . $apiKey;
    $response = file_get_contents($url);
    $data = json_decode($response, true);

    if (isset($data['results'][0]['geometry']['location'])) {
        return [
            'latitude' => $data['results'][0]['geometry']['location']['lat'],
            'longitude' => $data['results'][0]['geometry']['location']['lng'],
        ];
    }
    return null;
}

// Fetch and filter customer data
function getFilteredCustomers($connection, $lat, $lng, $distance) {
    $customers = [];
    $query = "SELECT id, name, address, latitude, longitude 
              FROM customers 
              WHERE latitude IS NOT NULL AND longitude IS NOT NULL";
    $result = $connection->query($query);

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $calculatedDistance = calculateDistance($lat, $lng, $row['latitude'], $row['longitude']);
            if ($calculatedDistance <= $distance) {
                $row['distance'] = round($calculatedDistance, 2);
                $customers[] = $row;
            }
        }
    }

    // Sort customers by distance in ascending order
    usort($customers, function($a, $b) {
        return $a['distance'] <=> $b['distance'];
    });

    return $customers;
}

// Handle custom address geocoding and filtering
if (isset($_GET['action']) && $_GET['action'] === 'geocode' && !empty($_GET['address'])) {
    $address = $_GET['address'];
    $coords = fetchCoordinates($address, $GOOGLE_API_KEY);
    header('Content-Type: application/json');
    echo json_encode($coords);
    exit;
}

// Fetch customers based on user input
$customers = [];
if (isset($_GET['lat'], $_GET['lng'], $_GET['distance'])) {
    $lat = (float)$_GET['lat'];
    $lng = (float)$_GET['lng'];
    $distance = (float)$_GET['distance'];
    $customers = getFilteredCustomers($connection, $lat, $lng, $distance);
} else {
    $query = "SELECT * FROM customers LIMIT 100";
    $result = $connection->query($query);

    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }
}

// Close the database connection
$connection->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Data with Location Filtering</title>
    <style>
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
        .filters {
            margin-bottom: 20px;
        }
        .filters input, .filters button {
            margin-right: 10px;
            padding: 5px;
        }
    </style>
    <script>
        function applyFilter(distance) {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(position => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    window.location.href = `?lat=${lat}&lng=${lng}&distance=${distance}`;
                }, () => {
                    alert('Failed to get your location. Please allow location access.');
                });
            } else {
                alert('Geolocation is not supported by your browser.');
            }
        }

        async function applyAddressFilter(distance) {
            const address = document.getElementById('addressInput').value.trim();
            if (address) {
                try {
                    const response = await fetch(`?action=geocode&address=${encodeURIComponent(address)}`);
                    const data = await response.json();
                    if (data.latitude && data.longitude) {
                        window.location.href = `?lat=${data.latitude}&lng=${data.longitude}&distance=${distance}`;
                    } else {
                        alert('Unable to fetch coordinates for the provided address.');
                    }
                } catch {
                    alert('Error fetching coordinates. Please try again.');
                }
            } else {
                alert('Please enter an address.');
            }
        }
    </script>
</head>
<body>
    <h1>Customer Data with Location Filtering</h1>
    <div class="filters">
        <input type="text" id="addressInput" placeholder="Enter address or ZIP code">
        <button onclick="applyAddressFilter(5)">Search (5 km)</button>
        <button onclick="applyAddressFilter(10)">Search (10 km)</button>
        <button onclick="applyAddressFilter(50)">Search (50 km)</button>
        <button onclick="applyAddressFilter(100)">Search (100 km)</button>
        <button onclick="applyAddressFilter(300)">Search (300 km)</button>
        <button onclick="applyAddressFilter(500)">Search (500 km)</button>
    </div>
    <div class="filters">
        <button onclick="applyFilter(5)">Search Nearby (5 km)</button>
        <button onclick="applyFilter(10)">Search Nearby (10 km)</button>
        <button onclick="applyFilter(50)">Search Nearby (50 km)</button>
        <button onclick="applyFilter(100)">Search Nearby (100 km)</button>
        <button onclick="applyFilter(300)">Search Nearby (300 km)</button>
        <button onclick="applyFilter(500)">Search Nearby (500 km)</button>
    </div>
    <table>
        <thead>
            <tr>
                <?php if (!empty($customers)) { ?>
                    <?php foreach (array_keys($customers[0]) as $key) { ?>
                        <th><?= htmlspecialchars($key) ?></th>
                    <?php } ?>
                    <th>Distance (km)</th>
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
                        <td><?= htmlspecialchars($customer['distance'] ?? 'N/A') ?></td>
                    </tr>
                <?php } ?>
            <?php } else { ?>
                <tr>
                    <td colspan="100%">No data found</td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
</body>
</html>