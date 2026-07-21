# Locker_WooCommerce

## Notes for developers

Use node v18, npm v8.19.4.

From the root directory run ```npm install``` which install node packages in the **node_modules** folder.

Then run ```npm run build``` which will create an **assets** folder at root level which is not under version control.

Finally run ```npm run finalise``` which copies the generated assets into **fortis-for-woocommerce/assets** which is
under version control.

## PHPCS

To test your phpcs, run ```vendor/bin/phpcs -ps pudo-shipping-for-woocommerce --standard=WordPress```.

To fix your phpcs, run
```vendor/bin/phpcbf -ps pudo-shipping-for-woocommerce --standard=WordPress```
