// Decorates every <input type="password"> on the page with a show/hide eye
// toggle. Runs on DOMContentLoaded so it picks up password fields anywhere
// in the app (registration step 1, login, spouse activation, future
// password-reset flows) without each form having to opt in.
//
// Behaviour:
//   - wraps the input in a relative span
//   - injects an absolute-positioned button on the right
//   - clicking toggles input.type between "password" and "text"
//   - aria-pressed + aria-label flip for screen readers
//   - autocomplete attribute is preserved so password managers still work
function decoratePasswordInputs(root = document) {
    const inputs = root.querySelectorAll('input[type="password"]:not([data-pw-decorated])');
    inputs.forEach((input) => {
        input.dataset.pwDecorated = '1';

        const wrap = document.createElement('span');
        wrap.className = 'relative block';
        input.parentNode.insertBefore(wrap, input);
        wrap.appendChild(input);

        // Right-pad the input so the eye doesn't overlap the cursor.
        input.classList.add('pr-11');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.tabIndex = -1;
        btn.className =
            'absolute inset-y-0 right-0 flex items-center px-3 text-slate-400 ' +
            'hover:text-brand-600 focus:text-brand-600 focus:outline-none ' +
            'transition-colors';
        btn.innerHTML = eyeOpenSvg();

        btn.addEventListener('click', () => {
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
            btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            btn.innerHTML = showing ? eyeOpenSvg() : eyeClosedSvg();
            input.focus({ preventScroll: true });
        });

        wrap.appendChild(btn);
    });
}

function eyeOpenSvg() {
    return (
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" ' +
        'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/>' +
        '<circle cx="12" cy="12" r="3"/></svg>'
    );
}

function eyeClosedSvg() {
    return (
        '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" ' +
        'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
        '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-6.5 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/>' +
        '<path d="M9.9 4.24A10.94 10.94 0 0 1 12 4c6.5 0 10 7 10 7a18.55 18.55 0 0 1-2.16 3.19"/>' +
        '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>' +
        '<line x1="2" y1="2" x2="22" y2="22"/></svg>'
    );
}

// ── ID-photo crop / pan / zoom ──────────────────────────────────────────────
// When the dashboard owner picks a photo, open a crop modal (3:4 passport
// frame) instead of submitting immediately. On save we export the cropped
// canvas to a JPEG and drop it back onto the form's file input, so the
// existing IdPhotoController upload path is unchanged.
import Cropper from 'cropperjs';

function initIdPhotoCropper() {
    const input = document.getElementById('idPhotoInput');
    const form = document.getElementById('idPhotoForm');
    const modal = document.getElementById('idPhotoCropModal');
    if (!input || !form || !modal) return;

    const image = document.getElementById('idPhotoCropImage');
    const saveBtn = document.getElementById('idPhotoCropSave');
    const cancelBtn = document.getElementById('idPhotoCropCancel');
    const zoom = document.getElementById('idPhotoCropZoom');

    let cropper = null;
    let lastZoom = 0;
    let objectUrl = null;

    const cleanup = () => {
        if (cropper) { cropper.destroy(); cropper = null; }
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        // Reset so picking the same file again re-fires "change".
        input.value = '';
        saveBtn.disabled = false;
        saveBtn.textContent = 'Save photo';
        if (zoom) { zoom.value = '0'; lastZoom = 0; }
    };

    input.addEventListener('change', () => {
        const file = input.files && input.files[0];
        if (!file) return;

        objectUrl = URL.createObjectURL(file);
        image.src = objectUrl;
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        if (cropper) cropper.destroy();
        cropper = new Cropper(image, {
            aspectRatio: 3 / 4,
            viewMode: 1,
            dragMode: 'move',
            autoCropArea: 1,
            background: false,
            responsive: true,
        });
    });

    if (zoom) {
        zoom.addEventListener('input', () => {
            if (!cropper) return;
            const v = parseFloat(zoom.value);
            cropper.zoom(v - lastZoom); // relative delta
            lastZoom = v;
        });
    }

    cancelBtn.addEventListener('click', cleanup);
    modal.addEventListener('click', (e) => { if (e.target === modal) cleanup(); });

    saveBtn.addEventListener('click', () => {
        if (!cropper) return;
        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';

        const canvas = cropper.getCroppedCanvas({
            width: 600,
            height: 800, // 3:4, comfortably inside the 200..4000 server bounds
            imageSmoothingQuality: 'high',
        });
        if (!canvas) { cleanup(); return; }

        canvas.toBlob((blob) => {
            if (!blob) { cleanup(); return; }
            const dt = new DataTransfer();
            dt.items.add(new File([blob], 'id-photo.jpg', { type: 'image/jpeg' }));
            input.files = dt.files;
            // Submit directly (don't re-trigger the change handler).
            form.submit();
        }, 'image/jpeg', 0.9);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    decoratePasswordInputs();
    initIdPhotoCropper();
});
