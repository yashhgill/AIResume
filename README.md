# Resume Generator — Starter Integration

This project provides a minimal native PHP REST API and React frontend that connects to the `ai_resume_db` database.

> **Note:** This copy has been updated to keep the database fully local, add
> Google Sign-In, switch the AI provider from Gemini to Groq, and give the
> frontend a colorful animated/3D redesign. See **[SETUP_CHANGES.md](./SETUP_CHANGES.md)**
> for exactly what changed and the extra setup steps (Groq key, Google Client ID).

## 📋 Requirements

Lihat `requirements.txt` untuk senarai lengkap system requirements.

**Minimum:**
- XAMPP >= 8.0.0 (dengan PHP >= 8.0.0 dan MySQL)
- Node.js >= 18.0.0 dan npm >= 9.0.0 (untuk React frontend)
- Modern web browser

## 🚀 Quick Setup

### 1. Install System Requirements

**Windows:**
- Download dan install [XAMPP](https://www.apachefriends.org/)
- Download dan install [Node.js](https://nodejs.org/)

**Linux/Mac:**
- Install Apache, PHP, MySQL melalui package manager
- Install Node.js dan npm

### 2. Setup Database

- Import SQL dump `ai_resume_db.sql` ke MySQL/MariaDB melalui phpMyAdmin atau CLI
- Atau buat database baru bernama `ai_resume_db`

### 3. Setup Backend (PHP)

- Salin folder `resume_generator` ke XAMPP `htdocs` folder:
  ```
  c:\xampp\htdocs\resume_generator
  ```
- Edit `api/config.php` untuk set database credentials:
  ```php
  define('DB_HOST', '127.0.0.1');
  define('DB_NAME', 'ai_resume_db');
  define('DB_USER', 'root');
  define('DB_PASS', ''); // atau password XAMPP anda
  ```

### 4. Install React Dependencies

Buka terminal dan navigate ke folder frontendreact:

```bash
cd resume_generator/frontendreact
npm install
```

Ini akan download semua dependencies yang diperlukan (React, Bootstrap, dll) dari `package.json`.

### 5. Start Servers

**Start XAMPP:**
- Buka XAMPP Control Panel
- Start **Apache**
- Start **MySQL**

**Start React Development Server (optional):**
```bash
cd resume_generator/frontendreact
npm run dev
```

Atau guna static files terus:
- Buka `http://localhost/resume_generator/frontendreact/index.html` dalam browser

### 6. Access Application

- **Frontend (React):** `http://localhost/resume_generator/frontendreact/index.html`
- **API Endpoints:** `http://localhost/resume_generator/api/`
- **React Dev Server:** `http://localhost:3000` (jika guna `npm run dev`)

## 📁 Project Structure

```
resume_generator/
├── api/                    # PHP REST API
│   ├── config.php          # Database configuration
│   ├── db.php              # PDO connection helper
│   ├── auth.php            # Authentication endpoints
│   ├── users.php           # User management
│   ├── resumes.php         # Resume CRUD
│   └── logs.php            # Activity logs
├── frontendreact/          # React Frontend
│   ├── package.json        # Node.js dependencies
│   ├── vite.config.js      # Vite configuration
│   └── *.html              # React pages
├── assets/                  # Static assets
└── requirements.txt         # System requirements
```

## 📦 Dependencies

**React Frontend** (install dengan `npm install`):
- React 18.2.0
- React DOM 18.2.0
- React Router DOM 6.20.0
- Axios 1.6.2
- Bootstrap 5.3.2
- Vite (dev dependency)

**PHP Backend:**
- Native PHP (no external dependencies)
- PDO MySQL extension

## 🔧 Development Commands

```bash
# Install dependencies
cd frontendreact
npm install

# Start development server
npm run dev

# Build for production
npm run build

# Preview production build
npm run preview
```

## 📝 Notes

- Frontend React menggunakan CDN untuk quick demo, tapi boleh setup dengan Vite untuk development yang lebih baik
- API menggunakan simple CORS-enabled native PHP responses untuk development
- Untuk production, harden authentication, validation, dan CORS policies
- Consider Laravel untuk full-featured apps jika perlu

## 🐛 Troubleshooting

**Database connection error:**
- Pastikan MySQL running dalam XAMPP
- Check credentials dalam `api/config.php`
- Pastikan database `ai_resume_db` wujud

**npm install error:**
- Pastikan Node.js dan npm dah install
- Try delete `node_modules` dan `package-lock.json`, then run `npm install` lagi

**Port already in use:**
- Change port dalam `vite.config.js` jika port 3000 dah digunakan
