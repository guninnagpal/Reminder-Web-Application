# Remindly 🔔
> A smart reminder web application that automatically syncs tasks to Google Calendar with optional Google Meet integration.

## Features
- 🔐 Google OAuth 2.0 Authentication
- 📅 Auto-sync reminders to Google Calendar
- 🎥 One-click Google Meet link generation
- 🗄️ MySQL database for storing user & reminder history
- 🔔 Email & popup notifications
- 🌙 Clean dark theme UI
- 📱 Responsive design

## Tech Stack
- **Frontend:** HTML, CSS, JavaScript
- **Backend:** PHP
- **Database:** MySQL
- **APIs:** Google Calendar API, Google OAuth 2.0

## Setup Instructions

### 1. Clone the repository
```bash
git clone https://github.com/yourusername/Reminder-Web-Application.git
```

### 2. Google Cloud Console Setup
- Go to [console.cloud.google.com](https://console.cloud.google.com)
- Create a new project
- Enable **Google Calendar API**
- Configure OAuth consent screen
- Create OAuth 2.0 credentials (Web Application)
- Add your redirect URI:
  ```
  http://localhost/your-folder/api/auth.php?action=callback
  ```

### 3. Configure credentials
```bash
# Copy example files
cp api/auth.example.php api/auth.php
cp api/auth_helpers.example.php api/auth_helpers.php
```
Fill in your **Client ID**, **Client Secret** and **Redirect URI** in both files.

### 4. Database setup
- Open phpMyAdmin
- Create a database named `remindly_db`
- Import `database.sql`

### 5. Run the app
- Place the project in your XAMPP `htdocs` folder
- Start Apache & MySQL in XAMPP
- Visit `http://localhost/your-folder/`

## Screenshots
> Coming soon

## License
MIT License
