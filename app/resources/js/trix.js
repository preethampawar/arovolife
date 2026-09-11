// Trix rich-text editor, bundled with the app rather than fetched from a CDN.
//
// It used to load from unpkg at render time. When that host was unreachable
// the <trix-editor> element was never defined, so the Body field degraded to
// an inert box with no console error and no visible sign anything was wrong —
// an admin could sit there typing into nothing. A bundled asset shares the
// app's own availability, and the editor page carries its own boot check.
//
// Its own entry point, not app.js: only the content editor needs it.
import 'trix';
import 'trix/dist/trix.css';
