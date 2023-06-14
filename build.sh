#!/usr/bin/env bash

composer install
bash copyImages.sh

PACKAGE_VERSION=$(cat composer.json \
  | grep version \
  | head -1 \
  | awk -F: '{ print $2 }' \
  | sed 's/[", ]//g')

rm -Rf $(pwd)/build
mkdir $(pwd)/build
mkdir $(pwd)/build/ccvonlinepayments

cp -LR $(pwd)/composer.json $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/logo.png $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/config.xml $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/index.php $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/ccvonlinepayments.php $(pwd)/build/ccvonlinepayments/

cp -LR $(pwd)/controllers $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/translations $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/upgrade $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/images $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/src $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/vendor $(pwd)/build/ccvonlinepayments/
cp -LR $(pwd)/views $(pwd)/build/ccvonlinepayments/

rm -Rf "$(pwd)"/build/ccvonlinepayments/vendor/ccv/php-lib/vendor
rm -Rf "$(pwd)"/build/ccvonlinepayments/vendor/ccv/images/vendor
rm -Rf "$(pwd)"/build/ccvonlinepayments/vendor/ccvonlinepayments/images

for D in `find $(pwd)/build/ccvonlinepayments/* -type d`
do
    cp $(pwd)/build/ccvonlinepayments/index.php $D/index.php
done

cd $(pwd)/build/
zip -9 -r ccvonlinepayments-prestashop-$PACKAGE_VERSION.zip ccvonlinepayments
cd ../
