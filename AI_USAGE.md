# AI Usage Disclosure

## Project: Cake Shop Ordering Management System
**Student:** Daren Manongsong
**Date:** March 26, 2026

---

## AI Tools Used

| AI Tool            | Purpose |
|---
| ChatGPT Deepseek /| Code generation, debugging, WebSocket implementation, SQL queries, CSS styling |
| Deepseek | Code completion and suggestions |


---

## How AI Was Used

AI tools were used to assist with:

1. **WebSocket Implementation** - Generated WebSocket server code using Ratchet library, created broadcasting functions for real-time notifications, developed JavaScript WebSocket client, and fixed connection issues.

2. **Database & Seed Data** - Generated SQL to create 200 customer records, created product table with image support, generated sample orders and order items, and created audit logs table.

3. **Product Management** - Generated PHP code for product management  added image upload functionality, and created audit logging system.

4. **Order System** - Implemented order placement with stock validation, created order token system to prevent duplicate orders, and added order history display.

5. **Frontend Development** - Generated HTML/CSS for admin and customer dashboards, created product display with images, implemented modal forms for ordering, and added real-time notification styling.

6. **CSS Styling** - Generated responsive CSS for product grids, cards, tables, forms, and notifications.

---

## WebSocket Setup and Integration

### 1. Installing Composer and Ratchet

**Step 1: Download Composer**

Composer is a dependency manager for PHP. To install it:

- Go to https://getcomposer.org/download/
- Download and install Composer for Windows
- During installation, select your PHP path: `C:\xampp\php\php.exe`
- After installation, restart VS Code or terminal

**Step 2: Open VS Code Terminal**

Open VS Code and open your project folder: