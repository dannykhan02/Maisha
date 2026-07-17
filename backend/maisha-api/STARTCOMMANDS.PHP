sudo service mysql start
sudo service redis-server start
redis-cli ping


php artisan queue:restart
php artisan queue:work --verbose

tail -f storage/logs/laravel.log
