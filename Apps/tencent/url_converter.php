    <?php
    include "./navbar.php";
    ?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8" />
        <title>QC URL Converter</title>
        <style>
            body {
                font-family: sans-serif;
                padding: 2rem;
            }

            #converter {
                margin-bottom: 1rem;
            }

            input {
                padding: 0.5rem;
                width: 300px;
            }

            button {
                padding: 0.5rem 1rem;
            }

            #result a {
                display: inline-block;
                margin-top: 1rem;
                color: blue;
                text-decoration: underline;
            }
        </style>
    </head>

    <body>
        <div  class="container">
            <div id="converter">
                <label for="raw">Paste raw text:</label><br />
                <input type="text" id="raw" placeholder="httpsqcurlcnXXXXXXXX" />
                <button id="go">Convert to Link</button>
            </div>
            <div id="result"></div>
        </div>
        <script>
            document.getElementById('go').addEventListener('click', function() {
                const raw = document.getElementById('raw').value.trim();
                // Replace the prefix "httpsqcurlcn" + code => "https://qc.url.cn/" + code
                const match = raw.match(/^httpsqcurlcn(.+)$/);
                if (!match) {
                    document.getElementById('result').innerText = 'Invalid format!';
                    return;
                }
                const code = match[1];
                const url = `https://qc.url.cn/${code}`;
                document.getElementById('result').innerHTML =
                    `<a href="${url}" target="_blank">${url}</a>`;
            });
        </script>
    </body>

    </html>