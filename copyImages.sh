rm -Rf $(pwd)/images

mkdir $(pwd)/images
mkdir $(pwd)/images/methods

cp -Rf $(pwd)/vendor/ccv/images/logo.png $(pwd)/logo.png
cp -Rf $(pwd)/vendor/ccv/images/methods/*.png $(pwd)/images/methods

cd $(pwd)/images/methods;
mogrify -resize 999x32 *.png

cd ../../../
