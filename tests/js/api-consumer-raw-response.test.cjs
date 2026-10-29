const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const bladePath = path.join(__dirname, '../../resources/views/api-credentials/endpoint-consumer-demo.blade.php');
const blade = fs.readFileSync(bladePath, 'utf8');

function extractFunction(name, nextFunctionName) {
    const start = blade.indexOf(`function ${name}(`);
    assert.notEqual(start, -1, `No se encontró ${name} en la vista.`);
    const end = blade.indexOf(`function ${nextFunctionName}(`, start);
    assert.notEqual(end, -1, `No se encontró ${nextFunctionName} después de ${name}.`);
    return blade.slice(start, end).trim();
}

test('el panel conserva literalmente el cuerpo que devolvió la API', () => {
    const empty = { classList: { add() {} } };
    const content = {
        classList: { remove() {} },
        children: [],
        replaceChildren() { this.children = []; },
        appendChild(element) { this.children.push(element); },
    };
    const document = {
        getElementById(id) {
            return id === 'consumerEmpty' ? empty : content;
        },
        createElement(tagName) {
            assert.equal(tagName, 'pre');
            return {
                className: '',
                attributes: {},
                textContent: '',
                setAttribute(name, value) { this.attributes[name] = value; },
            };
        },
    };
    const showApiResponse = new Function('document', `${extractFunction('showApiResponse', 'showError')}; return showApiResponse;`)(document);
    const raw = '{\n  "filters": {"destination_country_code": "BO"},\n  "dispatches": [],\n  "literal": "<api>& raw spaces"\n}';

    showApiResponse(raw);

    assert.equal(content.children.length, 1);
    assert.equal(content.children[0].textContent, raw);
    assert.equal(content.children[0].className, 'consumer-raw-response');
});

test('la respuesta HTTP se muestra sin pasar por el renderizador de resúmenes', () => {
    const requestHandlerStart = blade.indexOf('apiConsumerSend.addEventListener(\'click\'');
    assert.notEqual(requestHandlerStart, -1);
    const requestHandler = blade.slice(requestHandlerStart, blade.indexOf('window.addEventListener(\'pagehide\'', requestHandlerStart));

    assert.match(requestHandler, /showApiResponse\(raw\)/);
    assert.doesNotMatch(requestHandler, /renderConsumer\(responseData\)/);
    assert.doesNotMatch(requestHandler, /nextPageRequest\(responseData\)/);
});
