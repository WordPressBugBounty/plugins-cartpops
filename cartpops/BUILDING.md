# Build CartPops frontend assets

CartPops packages the authored JavaScript, JSX, and SCSS needed to reproduce its
compiled frontend assets. Run these commands from the plugin root with exactly
Node.js 24.20.0 and npm 11.19.0:

```sh
node --version
npm --version
npm ci
npm run build
```

The first two commands must report `v24.20.0` and `11.19.0`. `npm ci` installs
the exact dependency graph in `package-lock.json`. `npm run build` removes the
existing `assets/build/` directory and recreates the production JavaScript,
CSS, block metadata, PHP render copies, and asset metadata using
`webpack.config.js` and `@wordpress/scripts`.

The unified source and Pro package contain both shared source and `/src/Pro`, so
the build produces shared outputs plus `/assets/build/Pro`. Freemius prepares
the Free package by removing both `/src/Pro` and `/assets/build/Pro`; when that
complete Pro source root is absent, the same command builds only the shared
Free outputs. If `/src/Pro` exists, every declared Pro entry remains mandatory
and a partial paid source tree makes the build fail.

The build is local and deterministic input preparation only. It does not tag,
upload, deploy, publish, contact the Freemius product API, write to
WordPress.org, or create a GitHub Release.
