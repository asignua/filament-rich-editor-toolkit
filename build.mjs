// Compiles resources/js/src/*.js into committed, minified ES modules in resources/dist.
// Each entry is a standalone module (Filament loads it with a dynamic import()).
import { build } from 'esbuild'

const entries = ['paste-clean', 'custom-attributes', 'embed']

await Promise.all(
    entries.map((name) =>
        build({
            entryPoints: [`resources/js/src/${name}.js`],
            outfile: `resources/dist/${name}.js`,
            bundle: true,
            format: 'esm',
            minify: true,
            target: 'es2020',
            legalComments: 'none',
        }),
    ),
)
