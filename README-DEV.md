# 🚀 Development Scripts

Scripts untuk memudahkan development dengan melihat error dari backend dan frontend.

## 📋 Quick Start

### Option 1: Run Kedua-dua Sekali (Recommended)
Double-click atau run:
```bash
start-dev.bat
```
Ini akan buka 2 window berasingan:
- **Backend window** - Check status XAMPP Apache
- **Frontend window** - Vite dev server dengan error logs

### Option 2: Run Berasingan

**Backend sahaja:**
```bash
start-backend.bat
```
Check status XAMPP Apache dan backend endpoints.

**Frontend sahaja:**
```bash
start-frontend.bat
```
Start Vite dev server untuk frontend development.

### Option 3: Run dalam Satu Terminal
```bash
start-all.bat
```
Run frontend dalam terminal ini (backend perlu start XAMPP secara manual).

## 🔍 Checking for Errors

### Backend Errors
1. Buka **Backend window** (dari `start-dev.bat`)
2. Check untuk:
   - Apache running status
   - Database connection errors
   - API endpoint errors

**Manual check:**
- http://localhost/resume_generator/api/test_db.php
- `php api/list_models.php` (CLI) — confirms your Groq API key works

### Frontend Errors
1. Buka **Frontend window** (dari `start-dev.bat`)
2. Check terminal untuk:
   - Vite compilation errors
   - Module not found errors
   - Port already in use errors

**Browser console:**
- Open browser DevTools (F12)
- Check Console tab untuk JavaScript errors
- Check Network tab untuk API call errors

## 🛠️ Troubleshooting

### Port 3001 Already in Use
```bash
# Kill process on port 3001 (Windows)
netstat -ano | findstr :3001
taskkill /PID <PID> /F
```

### XAMPP Apache Not Starting
1. Check XAMPP Control Panel
2. Check if port 80 is used by another service
3. Try changing Apache port in XAMPP config

### Frontend Dependencies Missing
```bash
cd frontendreact
npm install
```

### Backend API Not Responding
1. Ensure XAMPP Apache is running
2. Check `api/config.php` database credentials
3. Ensure MySQL is running in XAMPP
4. Test: http://localhost/resume_generator/api/test_db.php

## 📝 Available Scripts

### Batch Files (Windows)
- `start-backend.bat` - Check backend status
- `start-frontend.bat` - Start Vite dev server
- `start-dev.bat` - Start both in separate windows
- `start-all.bat` - Start frontend in current terminal

### NPM Scripts (from frontendreact folder)
```bash
npm run dev          # Start Vite dev server
npm run build        # Build for production
npm run preview      # Preview production build
npm run start        # Alias for dev
```

## 🌐 URLs

- **Frontend Dev:** http://localhost:3001
- **Backend API:** http://localhost/resume_generator/api/
- **Static HTML:** http://localhost/resume_generator/frontendreact/

## 💡 Tips

1. **Keep both windows open** untuk tengok error real-time
2. **Use browser DevTools** untuk debug frontend issues
3. **Check XAMPP logs** untuk backend errors:
   - `C:\xampp\apache\logs\error.log`
   - `C:\xampp\mysql\data\*.err`
4. **Hot reload** - Frontend auto-reload bila ada perubahan

