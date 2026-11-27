const oMarkianDist = new URL('../../../markian/dist/', import.meta.url);
const sTag = 'exf-ai-markdown';

function loadScript(oUrl, sGlobal) {
    if (window[sGlobal]) return Promise.resolve();

    return new Promise((resolve, reject) => {
        const domScript = document.createElement('script');
        domScript.src = oUrl.href;
        domScript.onload = () => {
            if (window[sGlobal]) resolve();
            else reject(new Error(`AIChat could not initialize ${sGlobal} from ${oUrl.href}`));
        };
        domScript.onerror = () => reject(new Error(`AIChat could not load ${oUrl.href}`));
        document.head.append(domScript);
    });
}

function buildCodeRenderers() {
    const oRenderers = Object.create(null);
    for (const oLanguage of window.MarkianCode.supportedLanguages) {
        const oConfig = {
            ...oLanguage,
            source: new URL(`extensions/languages/${oLanguage.id}.min.js`, oMarkianDist).href
        };
        const fnRender = async ({language: sLanguage, source: sSource, signal: oSignal}) => {
            const domPre = document.createElement('pre');
            const domCode = document.createElement('code');
            domPre.append(domCode);
            domCode.className = `language-${sLanguage}`;
            if (sSource.length > 100000 || sSource.split('\n').length > 5000) {
                domCode.textContent = sSource;
                return domPre.outerHTML;
            }
            const oHighlighter = await window.MarkianCode.loadCodeLanguage(document, oConfig);
            if (oSignal.aborted) return '';
            const aTokens = await oHighlighter.highlight(sSource);
            if (oSignal.aborted) return '';
            if (!Array.isArray(aTokens) || aTokens.length > 100000) throw new TypeError('Invalid AIChat highlight tokens');
            let iOffset = 0;
            for (const oToken of aTokens) {
                if (!Number.isInteger(oToken.from) || !Number.isInteger(oToken.to)
                    || oToken.from < iOffset || oToken.to <= oToken.from || oToken.to > sSource.length
                    || !/^tok-[a-z-]+(?: tok-[a-z-]+)*$/.test(oToken.className)) {
                    throw new TypeError('Invalid AIChat highlight token');
                }
                domCode.append(sSource.slice(iOffset, oToken.from));
                const domToken = document.createElement('span');
                domToken.className = oToken.className;
                domToken.textContent = sSource.slice(oToken.from, oToken.to);
                domCode.append(domToken);
                iOffset = oToken.to;
            }
            domCode.append(sSource.slice(iOffset));
            return domPre.outerHTML;
        };
        for (const sName of [oLanguage.id, ...(oLanguage.aliases ?? [])]) oRenderers[sName] = fnRender;
    }
    return oRenderers;
}

function installRenderer() {
    const oViewer = loadScript(new URL('markian-viewer.min.js', oMarkianDist), 'MarkianViewer').catch(console.error);
    const oCode = loadScript(new URL('extensions/code.min.js', oMarkianDist), 'MarkianCode')
        .then(buildCodeRenderers).catch(console.error);
    const oMermaid = loadScript(new URL('extensions/viewer-mermaid.min.js', oMarkianDist), 'MarkianViewerMermaid')
        .then(() => window.MarkianViewerMermaid.createRenderer(document, {
            source: new URL('../../../../npm-asset/pele/dist/pele.global.js', import.meta.url).href
        })).catch(console.error);

    return Promise.all([oViewer, oCode, oMermaid]).then(([, oCodeRenderers, fnMermaid]) => {
        const oRenderers = { ...oCodeRenderers };
        if (fnMermaid) oRenderers.mermaid = fnMermaid;
        const sStylesheet = new URL('AIChatMarkdown.css', import.meta.url).href;

        customElements.define(sTag, class extends HTMLElement {
            #oViewer;
            #sMarkdown;

            connectedCallback() {
                // Let Deep Chat finish its empty-message check against the literal source first.
                queueMicrotask(() => {
                    if (!this.isConnected || this.#oViewer) return;
                    this.mountViewer();
                });
            }

            disconnectedCallback() {
                if (this.#oViewer) {
                    this.#oViewer.destroy();
                    this.#oViewer = undefined;
                    this.textContent = this.#sMarkdown;
                }
                this.removeAttribute('data-rendering');
            }

            mountViewer() {
                const domRoot = this.getRootNode();
                if (!domRoot.querySelector('link[data-exf-ai-markdown]')) {
                    const domLink = this.ownerDocument.createElement('link');
                    domLink.rel = 'stylesheet';
                    domLink.href = sStylesheet;
                    domLink.dataset.exfAiMarkdown = '';
                    domLink.onerror = () => console.error(`AIChat could not load ${sStylesheet}`);
                    domRoot.append(domLink);
                }

                if (!window.MarkianViewer) return;
                this.#sMarkdown = this.textContent;
                this.setAttribute('data-rendering', '');
                const domViewerHost = this.ownerDocument.createElement('div');
                this.replaceChildren(domViewerHost);
                try {
                    const oViewer = this.#oViewer = window.MarkianViewer.create(domViewerHost, {
                        markdown: this.#sMarkdown,
                        softbreaks: true,
                        mermaid: Boolean(fnMermaid),
                        renderers: oRenderers
                    });
                    // Let the active facade style Markdown without exposing the rest of the chat.
                    domViewerHost.querySelector('.markian-document')?.part.add('markdown-body');
                    domViewerHost.querySelectorAll('.markian-document thead th').forEach((domHeading) => {
                        domHeading.part.add('markdown-table-heading');
                    });
                    // Keep links opening in a new tab, except for anchors within this message.
                    domViewerHost.querySelectorAll('a[href]:not([href^="#"])').forEach((domLink) => {
                        domLink.target = '_blank';
                        domLink.rel = 'noopener noreferrer';
                    });
                    oViewer.ready.catch((oError) => {
                        if (this.#oViewer === oViewer) console.error(oError);
                    }).finally(() => {
                        if (this.#oViewer === oViewer) this.removeAttribute('data-rendering');
                    });
                } catch (oError) {
                    this.textContent = this.#sMarkdown;
                    this.removeAttribute('data-rendering');
                    console.error(oError);
                }
            }
        });
    });
}

// Keep original message text in Deep Chat and pass only escaped text to the viewer host.
(window.remarkable_plugins ??= []).push({
    plugin(oRemarkable) {
        if (!oRemarkable.options.exfMarkian) return;
        oRemarkable.render = (sMarkdown) => {
            const domHost = document.createElement('div');
            domHost.textContent = sMarkdown;
            return `<${sTag}>${domHost.innerHTML}</${sTag}>`;
        };
    }
});

try {
    await installRenderer();
} catch (oError) {
    console.error(oError);
}
if (!customElements.get('deep-chat')) {
    await import('../../../../npm-asset/deep-chat/dist/deepChat.bundle.js');
}
