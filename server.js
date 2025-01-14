const express = require('express');
const mysql = require('mysql2');
const axios = require('axios');
const path = require('path');
const app = express();
const port = 3000;

// Google Maps API Key
const GOOGLE_API_KEY = 'AIzaSyANRs0V5yc4UYmuJMilELDOHyKLGI7ayK4';

// MySQL connection configuration
const pool = mysql.createPool({
    host: 'localhost',
    user: 'root',
    password: 'RITOdas06$',
    database: 'MarketListings',
});

// Set EJS as the view engine
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Fetch precise coordinates using Google Maps API
async function fetchCoordinates(query) {
    const url = `https://maps.googleapis.com/maps/api/geocode/json?address=${encodeURIComponent(
        query
    )}&key=${GOOGLE_API_KEY}`;
    try {
        const response = await axios.get(url);
        const results = response.data.results;

        if (results.length > 0) {
            const location = results[0].geometry.location;
            return { latitude: location.lat, longitude: location.lng };
        }
    } catch (error) {
        console.error(`Error fetching coordinates for "${query}": ${error.message}`);
    }
    return { latitude: null, longitude: null };
}

// Extract ZIP code from an address string
function extractZipCode(address) {
    const zipCodeRegex = /\b\d{5}(?:-\d{4})?\b|\b\d{6}\b/; // Matches standard 5-digit or 6-digit ZIP codes
    const match = address.match(zipCodeRegex);
    return match ? match[0] : null;
}

// Update coordinates in the database
function updateCoordinatesInDatabase(id, latitude, longitude) {
    return new Promise((resolve, reject) => {
        pool.query(
            'UPDATE customers SET latitude = ?, longitude = ? WHERE id = ?',
            [latitude, longitude, id],
            (err, results) => {
                if (err) {
                    return reject(err);
                }
                resolve(results);
            }
        );
    });
}

// Calculate distance between two coordinates using Haversine formula
function calculateDistance(lat1, lng1, lat2, lng2) {
    const R = 6371; // Radius of Earth in km
    const dLat = (lat2 - lat1) * (Math.PI / 180);
    const dLng = (lng2 - lng1) * (Math.PI / 180);
    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(lat1 * (Math.PI / 180)) * Math.cos(lat2 * (Math.PI / 180)) *
        Math.sin(dLng / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

// Initialize geographical coordinates for all customers
async function initializeCoordinates() {
    console.log("Initializing coordinates for all customers...");

    pool.query('SELECT * FROM customers', async (err, results) => {
        if (err) {
            console.error('Error fetching customers:', err);
            return;
        }

        for (let customer of results) {
            const { id, address, zip_code } = customer;

            // Skip customers that already have coordinates
            if (customer.latitude && customer.longitude) {
                continue;
            }

            let coords = await fetchCoordinates(address);

            if (!coords.latitude || !coords.longitude) {
                // If coordinates are not found by address, attempt to use ZIP code
                const zipCode = extractZipCode(address);
                if (zipCode) {
                    console.log(`Attempting to fetch coordinates using ZIP code: ${zipCode}`);
                    coords = await fetchCoordinates(zipCode);
                }
            }

            if (coords.latitude && coords.longitude) {
                console.log(`Updating coordinates for customer ${id}`);
                await updateCoordinatesInDatabase(id, coords.latitude, coords.longitude);
            } else {
                console.log(`Unable to fetch coordinates for customer ${id}`);
            }
        }
    });
}

// Fetch all customers and filter based on location or custom address
app.get('/', async (req, res) => {
    const userLat = parseFloat(req.query.lat) || null;
    const userLng = parseFloat(req.query.lng) || null;
    const filterDistance = parseFloat(req.query.distance) || null;
    const address = req.query.address || null;

    // Fetch all customers from the database
    pool.query('SELECT * FROM customers', async (err, results) => {
        if (err) {
            console.error('Error fetching data:', err);
            res.status(500).send('Error fetching data');
            return;
        }

        // If no address or location-based parameters are provided, show all customers with coordinates
        if (!address && !userLat && !userLng && !filterDistance) {
            res.render('page', { customers: results });
            return;
        }

        // Determine base coordinates (user location or custom address-based)
        let baseLat = userLat;
        let baseLng = userLng;

        if (address && (!userLat || !userLng)) {
            // Try fetching coordinates of the full address
            let addressCoords = await fetchCoordinates(address);

            if (!addressCoords.latitude || !addressCoords.longitude) {
                // If coordinates for full address not found, try fetching by ZIP code
                const zipCode = extractZipCode(address);
                if (zipCode) {
                    console.log(`Attempting to fetch coordinates using ZIP code: ${zipCode}`);
                    addressCoords = await fetchCoordinates(zipCode);
                }
            }

            baseLat = addressCoords.latitude;
            baseLng = addressCoords.longitude;
        }

        // If no valid coordinates are available, return all customers
        if (!baseLat || !baseLng) {
            res.render('page', { customers: results });
            return;
        }

        // Filter customers based on distance
        const filteredCustomers = results
            .map((customer) => {
                if (customer.latitude && customer.longitude) {
                    const distance = calculateDistance(
                        baseLat,
                        baseLng,
                        customer.latitude,
                        customer.longitude
                    );
                    return { ...customer, distance };
                }
                return { ...customer, distance: Infinity }; // Customers without coordinates are ignored
            })
            .filter((customer) => customer.distance <= filterDistance)
            .sort((a, b) => a.distance - b.distance); // Sort by distance

        res.render('page', { customers: filteredCustomers });
    });
});

// Geocoding route for custom address input
app.get('/geocode', async (req, res) => {
    const address = req.query.address;
    if (!address) {
        res.status(400).send({ error: 'Address is required' });
        return;
    }

    let coords = await fetchCoordinates(address);
    if (!coords.latitude || !coords.longitude) {
        // If the address does not work, try using the ZIP code
        const zipCode = extractZipCode(address);
        if (zipCode) {
            console.log(`Attempting to fetch coordinates using ZIP code: ${zipCode}`);
            coords = await fetchCoordinates(zipCode);
        }
    }

    if (coords.latitude && coords.longitude) {
        res.send(coords);
    } else {
        res.status(404).send({ error: 'Address or ZIP code not found' });
    }
});

// Initialize coordinates at server startup
initializeCoordinates();

// Start the server
app.listen(port, () => {
    console.log(`Server listening on port ${port}`);
});
