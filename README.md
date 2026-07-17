1. Run "composer install"
2. Run php artisan key:generate
3. create a new database or get one from developers
4. Change APP_URL, and Database details in .env
5. Run the migration command - "php artisan migrate" - add all new files into project 
6. cache clear command - php artisan optimise:clear
7. Run seed - php artisan db:seed
8. php artisan serve - runs a localserver

After creating .env file you should add
App_URL - takes localhost url with project name - https://localhost/projectname
Database_name = database name defined in pgsql
db_username = root as default if you have created it else use given db file name
db_password =  you created password else use given db password