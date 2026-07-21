const defaultConfig = require('@wordpress/scripts/config/webpack.config')
const DependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin')
const path = require('path')

module.exports = {
  ...defaultConfig,
  entry: {
    index: path.resolve(__dirname, 'resources/js/frontend/index.js'),
  },
  output: {
    path: path.resolve(__dirname, 'pudo-shipping-for-woocommerce/assets/js'),
    filename: '[name].js',
  },
  plugins: [
    ...defaultConfig.plugins.filter(
      (plugin) =>
        plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
    ),
    new DependencyExtractionWebpackPlugin(),
  ],
}
