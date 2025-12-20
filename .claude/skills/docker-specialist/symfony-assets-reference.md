# Symfony Assets Reference

## Quick Reference

### AssetMapper Commands

| Command | Purpose |
|---------|---------|
| `php bin/console importmap:install` | Install all importmap dependencies |
| `php bin/console importmap:require <package>` | Add new package |
| `php bin/console importmap:remove <package>` | Remove package |
| `php bin/console importmap:update` | Update all packages |
| `php bin/console importmap:audit` | Check for vulnerabilities |
| `php bin/console asset-map:compile` | Compile assets for production |
| `php bin/console debug:asset-map` | Debug asset mapping |

### Stimulus Controller Lifecycle

| Method | When Called |
|--------|-------------|
| `initialize()` | Once when controller class is first loaded |
| `connect()` | Each time controller connects to DOM element |
| `disconnect()` | Each time controller disconnects from DOM |

---

## AssetMapper Architecture

### Directory Structure

```
project/
├── importmap.php              # Package definitions (like package.json)
├── assets/
│   ├── app.js                 # Main entry point
│   ├── bootstrap.js           # Stimulus setup
│   ├── controllers/           # Stimulus controllers
│   │   ├── hello_controller.js
│   │   ├── modal_controller.js
│   │   └── ...
│   ├── styles/
│   │   ├── app.css           # Main stylesheet
│   │   ├── dark-mode.css     # Theme overrides
│   │   └── premium.css       # Feature-specific
│   └── images/               # Static images
├── public/
│   └── assets/               # Compiled/versioned assets
└── config/
    └── packages/
        └── asset_mapper.yaml # AssetMapper configuration
```

### Configuration (asset_mapper.yaml)

```yaml
framework:
    asset_mapper:
        # Where to look for assets
        paths:
            - assets/
        # Missing import mode (strict in prod)
        missing_import_mode: warn
        # Excluded patterns
        excluded_patterns:
            - '*/tests/*'
            - '*/.git/*'
```

---

## Stimulus Patterns

### Basic Controller

```javascript
// assets/controllers/example_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    // Define DOM elements to find
    static targets = ['output', 'input', 'button'];

    // Define values that can be passed from HTML
    static values = {
        url: String,
        count: { type: Number, default: 0 },
        config: Object,
        items: Array
    };

    // Define CSS classes to apply
    static classes = ['active', 'loading', 'error'];

    connect() {
        console.log('Controller connected');
        console.log('URL value:', this.urlValue);
    }

    disconnect() {
        console.log('Controller disconnected');
    }

    // Action methods (called from HTML)
    submit(event) {
        event.preventDefault();
        this.loadingClass && this.element.classList.add(this.loadingClass);
        // ... do work
    }

    // Value changed callbacks
    countValueChanged(newValue, oldValue) {
        console.log(`Count changed from ${oldValue} to ${newValue}`);
    }
}
```

### Twig Integration

```twig
{# Basic usage #}
<div data-controller="example"
     data-example-url-value="{{ path('api_endpoint') }}"
     data-example-count-value="5"
     data-example-config-value="{{ {key: 'value'}|json_encode|e('html_attr') }}">

    {# Target elements #}
    <input type="text" data-example-target="input">
    <button data-action="click->example#submit" data-example-target="button">
        Submit
    </button>
    <div data-example-target="output"></div>
</div>

{# Multiple controllers on same element #}
<div data-controller="dropdown modal"
     data-dropdown-open-value="false"
     data-modal-backdrop-value="true">
    ...
</div>

{# Action with parameters #}
<button data-action="click->example#update"
        data-example-id-param="123"
        data-example-type-param="user">
    Update
</button>
```

### Controller with Fetch API

```javascript
// assets/controllers/async_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content', 'loading', 'error'];
    static values = { url: String };

    async connect() {
        await this.load();
    }

    async load() {
        this.showLoading();

        try {
            const response = await fetch(this.urlValue);
            if (!response.ok) throw new Error('Network error');

            const data = await response.json();
            this.render(data);
        } catch (error) {
            this.showError(error.message);
        }
    }

    showLoading() {
        this.loadingTarget.classList.remove('d-none');
        this.contentTarget.classList.add('d-none');
        this.errorTarget.classList.add('d-none');
    }

    render(data) {
        this.loadingTarget.classList.add('d-none');
        this.contentTarget.classList.remove('d-none');
        this.contentTarget.innerHTML = this.formatData(data);
    }

    showError(message) {
        this.loadingTarget.classList.add('d-none');
        this.errorTarget.classList.remove('d-none');
        this.errorTarget.textContent = message;
    }

    formatData(data) {
        return `<pre>${JSON.stringify(data, null, 2)}</pre>`;
    }
}
```

### Controller with Events

```javascript
// assets/controllers/emitter_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    save() {
        // Dispatch custom event
        this.dispatch('saved', {
            detail: { id: 123, name: 'Example' },
            prefix: 'custom',  // Event name: custom:saved
            bubbles: true
        });
    }
}

// assets/controllers/listener_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    // Listen via data-action
    handleSave(event) {
        console.log('Saved:', event.detail);
    }
}
```

```twig
{# Event handling in HTML #}
<div data-controller="listener"
     data-action="custom:saved->listener#handleSave">
    <div data-controller="emitter">
        <button data-action="click->emitter#save">Save</button>
    </div>
</div>
```

---

## Turbo Patterns

### Turbo Drive (Full Page Navigation)

```javascript
// Disable Turbo for specific links
document.addEventListener('turbo:before-visit', (event) => {
    if (event.detail.url.includes('/legacy/')) {
        event.preventDefault();
        window.location.href = event.detail.url;
    }
});

// Handle page load
document.addEventListener('turbo:load', () => {
    // Initialize third-party libraries
    initCharts();
    initTooltips();
});
```

### Turbo Frames (Partial Updates)

```twig
{# Define a frame #}
<turbo-frame id="user-list">
    {% for user in users %}
        <div>{{ user.name }}</div>
    {% endfor %}
</turbo-frame>

{# Link updates only the frame #}
<a href="{{ path('user_list', {page: 2}) }}" data-turbo-frame="user-list">
    Next Page
</a>

{# Form updates only the frame #}
<turbo-frame id="search-results">
    <form action="{{ path('search') }}" method="get" data-turbo-frame="search-results">
        <input type="search" name="q">
        <button>Search</button>
    </form>
    <div id="results">
        {% for item in results %}...{% endfor %}
    </div>
</turbo-frame>

{# Lazy loading frame #}
<turbo-frame id="lazy-content" src="{{ path('lazy_endpoint') }}" loading="lazy">
    <p>Loading...</p>
</turbo-frame>
```

### Turbo Streams (Real-time Updates)

```php
// Controller returning Turbo Stream
#[Route('/item/create', methods: ['POST'])]
public function create(Request $request): Response
{
    // ... create item

    if ($request->headers->get('Accept') === 'text/vnd.turbo-stream.html') {
        return $this->render('item/_stream.html.twig', [
            'item' => $item
        ]);
    }

    return $this->redirectToRoute('item_index');
}
```

```twig
{# item/_stream.html.twig #}
<turbo-stream action="append" target="item-list">
    <template>
        <div id="item-{{ item.id }}">{{ item.name }}</div>
    </template>
</turbo-stream>

<turbo-stream action="update" target="item-count">
    <template>{{ total }} items</template>
</turbo-stream>
```

**Turbo Stream Actions:**
- `append` - Add to end of target
- `prepend` - Add to beginning of target
- `replace` - Replace entire target
- `update` - Replace inner HTML of target
- `remove` - Remove target element
- `before` - Insert before target
- `after` - Insert after target

---

## Common Stimulus Controllers

### Modal Controller

```javascript
// assets/controllers/modal_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['dialog'];
    static values = { open: Boolean };

    connect() {
        this.boundClose = this.closeOnEscape.bind(this);
        if (this.openValue) this.open();
    }

    open() {
        this.dialogTarget.showModal();
        document.addEventListener('keydown', this.boundClose);
        document.body.style.overflow = 'hidden';
    }

    close() {
        this.dialogTarget.close();
        document.removeEventListener('keydown', this.boundClose);
        document.body.style.overflow = '';
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') this.close();
    }

    closeOnBackdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }
}
```

### Form Validation Controller

```javascript
// assets/controllers/form_validation_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['submit', 'field'];

    connect() {
        this.validate();
    }

    validate() {
        const allValid = this.fieldTargets.every(field => field.checkValidity());
        this.submitTarget.disabled = !allValid;
    }

    fieldTargetConnected(field) {
        field.addEventListener('input', () => this.validate());
        field.addEventListener('blur', () => this.showError(field));
    }

    showError(field) {
        const errorEl = field.nextElementSibling;
        if (errorEl?.classList.contains('invalid-feedback')) {
            errorEl.textContent = field.validationMessage;
        }
    }
}
```

### Debounce Search Controller

```javascript
// assets/controllers/search_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'results'];
    static values = { url: String, delay: { type: Number, default: 300 } };

    search() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.performSearch(), this.delayValue);
    }

    async performSearch() {
        const query = this.inputTarget.value;
        if (query.length < 2) {
            this.resultsTarget.innerHTML = '';
            return;
        }

        const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`);
        this.resultsTarget.innerHTML = await response.text();
    }
}
```

### Dark Mode Controller

```javascript
// assets/controllers/dark_mode_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { key: { type: String, default: 'theme' } };

    connect() {
        this.applyTheme(this.currentTheme);
    }

    toggle() {
        const newTheme = this.currentTheme === 'dark' ? 'light' : 'dark';
        this.applyTheme(newTheme);
        localStorage.setItem(this.keyValue, newTheme);
    }

    get currentTheme() {
        return localStorage.getItem(this.keyValue) ||
               (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    }

    applyTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
    }
}
```

---

## Docker Integration

### Development Build

```dockerfile
# No special build step needed - AssetMapper serves directly
FROM php:8.4-fpm-bookworm AS development

# Install Node for importmap resolution
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs

COPY . /var/www/html
RUN php bin/console importmap:install
```

### Production Build

```dockerfile
FROM php:8.4-fpm-bookworm AS production

# Install Node for asset compilation
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html
WORKDIR /var/www/html

# Install and compile assets
RUN php bin/console importmap:install
RUN php bin/console asset-map:compile

# Remove Node after compilation (optional, saves space)
RUN apt-get purge -y nodejs && apt-get autoremove -y
```

### Nginx Configuration for Assets

```nginx
# Serve compiled assets with long cache
location /assets/ {
    expires 1y;
    add_header Cache-Control "public, immutable";
    try_files $uri =404;
}

# Serve original assets (development)
location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2)$ {
    expires 7d;
    add_header Cache-Control "public";
}
```

---

## Troubleshooting

### Common Issues

**Issue:** JavaScript not loading
```bash
# Check importmap is valid
php bin/console debug:asset-map

# Reinstall packages
php bin/console importmap:install
```

**Issue:** Stimulus controller not connecting
```javascript
// Check controller is registered
console.log(window.Stimulus.debug = true);
// Look for registration messages in console
```

**Issue:** Turbo breaks third-party library
```twig
{# Disable Turbo for specific element #}
<div data-turbo="false">
    <!-- Legacy content -->
</div>

{# Disable Turbo for specific link #}
<a href="/legacy" data-turbo="false">Legacy Page</a>
```

**Issue:** Assets not updating in production
```bash
# Clear compiled assets
rm -rf public/assets/*

# Recompile
php bin/console asset-map:compile

# Clear Symfony cache
php bin/console cache:clear --env=prod
```

### Debug Mode

```javascript
// Enable Stimulus debug mode
// assets/bootstrap.js
import { Application } from '@hotwired/stimulus';

const application = Application.start();
application.debug = true;  // Enable in development
window.Stimulus = application;
```

```twig
{# Show asset map in template (development only) #}
{% if app.debug %}
    <!-- Debug: {{ importmap_script_tags()|raw }} -->
{% endif %}
```

---

## Best Practices

### Performance

1. **Lazy Load Controllers**
   ```javascript
   // Only load when needed
   import { Controller } from '@hotwired/stimulus';

   export default class extends Controller {
       async connect() {
           const { Chart } = await import('chart.js');
           // Use Chart
       }
   }
   ```

2. **Minimize Dependencies**
   - Use native browser APIs when possible
   - Avoid large libraries for simple tasks
   - Consider bundle size impact

3. **Cache Busting**
   - AssetMapper handles this automatically
   - Versioned filenames in production

### Organization

1. **One Controller Per File**
2. **Descriptive Names**: `user_form_controller.js`, not `form_controller.js`
3. **Group Related Controllers**: Use subdirectories if needed
4. **Document Complex Logic**: Comments for non-obvious behavior

### Accessibility

1. **Focus Management**
   ```javascript
   open() {
       this.dialogTarget.showModal();
       this.dialogTarget.querySelector('[autofocus]')?.focus();
   }
   ```

2. **Announce Changes**
   ```javascript
   update(message) {
       const live = document.querySelector('[aria-live]');
       live.textContent = message;
   }
   ```

3. **Keyboard Navigation**
   ```javascript
   keydown(event) {
       switch(event.key) {
           case 'ArrowDown': this.focusNext(); break;
           case 'ArrowUp': this.focusPrevious(); break;
           case 'Enter': this.select(); break;
       }
   }
   ```
