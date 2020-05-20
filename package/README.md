# stripe payment

JavaScript for Stripe Payment

## Development

Install `nodejs` and `yarn`, then install the needed dependencies:

    apt install nodejs yarn
    yarn install

Use the different `yarn` scripts for the development workflow:

    yarn lint
    yarn dev

For building a releaseable artifact (library file) use:

    yarn dist

Build the js and copy it in the right place for Drupal with either:

    yarn drupal:dev
    yarn drupal:dist
