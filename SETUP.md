# 🚀 Quick Setup Guide

## Langkah 1: Install Requirements

### Download & Install:
1. **XAMPP** - https://www.apachefriends.org/
2. **Node.js** - https://nodejs.org/ (pilih LTS version)

### Verify Installation:
```bash
# Check PHP version
php -v

# Check Node.js version
node -v

# Check npm version
npm -v
```

## Langkah 2: Setup Database

1. Start XAMPP Control Panel
2. Start **MySQL**
3. Buka phpMyAdmin: http://localhost/phpmyadmin
4. Import `ai_resume_db.sql` atau create database baru bernama `ai_resume_db`

## Langkah 3: Setup Backend

1. Copy folder `resume_generator` ke:
   ```
   c:\xampp\htdocs\resume_generator
   ```

2. Edit `api/config.php`:
   ```php
   define('DB_HOST', '127.0.0.1');
   define('DB_NAME', 'ai_resume_db');
   define('DB_USER', 'root');
   define('DB_PASS', ''); // kosong atau password XAMPP anda
   ```

## Langkah 4: Install React Dependencies

Buka terminal/PowerShell dan run:

```bash
cd c:\xampp\htdocs\resume_generator\frontendreact
npm install
```

Tunggu sampai semua dependencies download siap.

## Langkah 5: Start Servers

1. **XAMPP Control Panel:**
   - Start **Apache** ✅
   - Start **MySQL** ✅

2. **React Dev Server (optional):**
   ```bash
   cd c:\xampp\htdocs\resume_generator\frontendreact
   npm run dev
   ```

## Langkah 6: Buka Aplikasi

- **Static HTML:** http://localhost/resume_generator/frontendreact/index.html
- **React Dev Server:** http://localhost:3000 (jika guna `npm run dev`)

## ✅ Checklist

- [ ] XAMPP installed
- [ ] Node.js installed
- [ ] Database `ai_resume_db` created
- [ ] `api/config.php` configured
- [ ] Dependencies installed (`npm install`)
- [ ] Apache & MySQL running
- [ ] Application accessible in browser

## 🆘 Troubleshooting

**npm install error?**
```bash
# Delete node_modules dan install balik
rm -rf node_modules package-lock.json
npm install
```

**Port 3000 dah digunakan?**
- Edit `vite.config.js` dan change port number

**Database connection failed?**
- Check MySQL running dalam XAMPP
- Verify credentials dalam `api/config.php`
- Pastikan database wujud

