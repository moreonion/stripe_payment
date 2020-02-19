const webpack = require('webpack')
const path = require('path')

const config = {
  entry: './src/main.js',
  output: {
    path: path.resolve(__dirname, 'dist'),
    filename: 'stripe.min.js',
  },
  module: {
    rules: [
      {
        // Execute eslint-loader before any transformations.
        enforce: 'pre',
        test: /\.js$/,
        loader: 'eslint-loader',
        exclude: /node_modules/,
      },
      {
        test: /\.js$/,
        loader: 'babel-loader',
        exclude: /node_modules/,
        options: {
          presets: [
            ['@babel/preset-env', {
              //'debug': true,
              'useBuiltIns': 'usage',
              'corejs': 3,
            }],
          ],
        },
      },
    ]
  },
}

module.exports = config
