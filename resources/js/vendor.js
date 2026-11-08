// Bootstrap JS из npm (раньше — CDN). Шаблоны обращаются к window.bootstrap
// (например, bootstrap.Tab в admin/departments/show) — только после DOMContentLoaded:
// модуль выполняется позже обычного скрипта в <head>, но раньше этого события.
import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;
