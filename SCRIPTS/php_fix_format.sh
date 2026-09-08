#curl -L https://cs.symfony.com/download/php-cs-fixer-v3.phar -o php-cs-fixer

#chmod a+x php-cs-fixer

#sudo mv php-cs-fixer /usr/local/bin/php-cs-fixer

php-cs-fixer fix /home/alex/develop/lopds/www/ --rules=@PSR12


#find . -name "*.php" | xargs -n 1 php -l