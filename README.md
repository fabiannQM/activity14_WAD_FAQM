# Notes API CRUD

REST API para notas personales con PHP, SQLite y Vue.

## Abrir

```text
http://localhost/Application-Web-Design/activity14/
```

## Archivos

- `api.php` - API
- `index.html` - frontend
- `assets/css/app.css` - estilos
- `assets/js/app.js` - Vue
- `ER-diagram.mmd` - diagrama ER

## Endpoints

```text
POST   /api.php/auth/signup
POST   /api.php/auth/login
GET    /api.php/auth/user
GET    /api.php/auth/logout

GET    /api.php/notes
GET    /api.php/notes/1
POST   /api.php/notes
PUT    /api.php/notes/1
DELETE /api.php/notes/1
GET    /api.php/categories
```
