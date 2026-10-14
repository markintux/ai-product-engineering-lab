# Personalized Pub Product Recommender

A web application for a pub that displays customer profiles and the menu (beers, burgers,
sides, appetizers and drinks), with the ability to track customer purchases for future
machine learning recommendations using TensorFlow.js.

## Project Structure

- `index.html` - Main HTML file for the application
- `src/index.js` - Entry point for the application
- `src/view/` - Contains classes for managing the DOM and templates
- `src/controller/` - Contains controllers to connect views and services
- `src/service/` - Contains business logic for data handling
- `src/workers/` - Web worker where the recommendation model is trained
- `data/` - JSON files with customer and product data

## Data

| File | Content |
|---|---|
| `data/products_pub.json` | Pub menu: 10 products with `category`, `price` and `color` |
| `data/users_pub.json` | 5 customers with their purchase history (items from the pub menu) |
| `data/products.json`, `data/users.json` | Original e-commerce sample data from the template (not loaded) |

## Setup and Run

1. Install dependencies:
```
npm install
```

2. Start the application:
```
npm start
```

3. Open your browser and navigate to `http://localhost:3000`

## Features

- Customer profile selection with details display
- Past purchase history display
- Menu listing with "Buy Now" functionality
- Purchase tracking using sessionStorage

## Future Enhancements

- TensorFlow.js-based recommendation engine (the training worker is still a stub)
- Customer similarity analysis
- Product recommendation based on purchase history
