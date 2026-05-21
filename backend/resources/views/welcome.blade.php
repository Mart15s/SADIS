<!doctype html>
<html lang="lt">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name', 'SADiS') }}</title>
        <style>
            body {
                align-items: center;
                background: #f4f7f1;
                color: #173123;
                display: grid;
                font-family: system-ui, sans-serif;
                margin: 0;
                min-height: 100vh;
                padding: 2rem;
            }

            main {
                margin: 0 auto;
                max-width: 36rem;
            }

            h1 {
                font-size: clamp(2rem, 5vw, 3.5rem);
                letter-spacing: 0;
                margin: 0 0 0.75rem;
            }

            p {
                line-height: 1.5;
                margin: 0;
            }
        </style>
    </head>
    <body>
        <main>
            <h1>SADiS API</h1>
            <p>Asmeninio sodo ar daržo informacinės sistemos backend veikia.</p>
        </main>
    </body>
</html>
