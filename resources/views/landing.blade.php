<x-layouts.landing>
    <svg class="icon-library" aria-hidden="true">
      <symbol id="icon-chat" viewBox="0 0 64 64">
        <rect x="15" y="5" width="34" height="54" rx="7" />
        <path d="M25 52h14" />
        <path d="M22 14h17a5 5 0 0 1 5 5v5a5 5 0 0 1-5 5h-8l-6 5v-5h-3a5 5 0 0 1-5-5v-5a5 5 0 0 1 5-5Z" />
        <path class="accent-stroke" d="M24 21h2m5 0h2m5 0h2" />
      </symbol>
      <symbol id="icon-brain" viewBox="0 0 64 64">
        <path d="M31.5 14c-3.5-6-12.5-4.5-13.5 2.5-6.5.5-8.5 9-3.5 12.5-5 5-1 13.5 5.5 13.5.5 7 9 9 12 3.5V14Z" />
        <path d="M32.5 14c3.5-6 12.5-4.5 13.5 2.5 6.5.5 8.5 9 3.5 12.5 5 5 1 13.5-5.5 13.5-.5 7-9 9-12 3.5V14Z" />
        <path class="accent-stroke" d="M24 20c5 1 6 5 5 9m-10 0c6 0 9 4 9 9m12-18c-5 1-6 5-5 9m10 0c-6 0-9 4-9 9" />
      </symbol>
      <symbol id="icon-list" viewBox="0 0 64 64">
        <rect x="14" y="8" width="36" height="50" rx="5" />
        <path d="M24 5h16v8H24z" />
        <path class="accent-stroke" d="m20 24 3 3 5-6m-8 16 3 3 5-6m-8 16 3 3 5-6" />
        <path d="M33 24h11m-11 13h11m-11 13h11" />
      </symbol>
      <symbol id="icon-cart" viewBox="0 0 64 64">
        <path d="M8 19h8l4 31h31l5-22H18" />
        <path d="m24 28 2-12m8 12V12m9 16 4-13" />
        <path class="accent-stroke" d="M21 36h32M23 43h28" />
        <circle cx="25" cy="56" r="3" />
        <circle cx="47" cy="56" r="3" />
      </symbol>
      <symbol id="icon-relax" viewBox="0 0 64 64">
        <circle cx="32" cy="32" r="23" />
        <path d="M13 25h11l3 7H15l-2-7Zm27 0h11l-2 7H37l3-7Zm-13 3h10" />
        <path class="accent-stroke" d="M23 42c5 5 13 5 18 0" />
        <path d="M20 13 15 7m29 6 5-6" />
      </symbol>
    </svg>

    <header class="site-header">
      <div class="shell header-inner">
        <a class="brand" href="#top" aria-label="Хто шо? — на початок">
          <span>ХТО</span>
          <span>ШО<i>?</i></span>
        </a>

        <nav class="main-nav" aria-label="Головна навігація">
          <a href="#how">Як це працює</a>
          <a href="#example">Приклад</a>
          <a href="#about">Про нас</a>
        </nav>

        <a class="help-link" href="#example">
          <span aria-hidden="true">?</span>
          <b>Допомога</b>
        </a>
      </div>
    </header>

    <main id="top">
      <section class="hero shell" aria-labelledby="hero-title">
        <div class="hero-copy">
          <p class="hero-question">Плануєте шашлики з друзями?</p>
          <h1 id="hero-title">
            В чаті —<br />бардак.
            <em>У кошику — порядок.</em>
          </h1>
          <p>
            Скидайте скріншоти з чатів — ми зрозуміємо, хто що їсть,
            хто що принесе і що потрібно купити. Додамо все в кошик Сільпо.
          </p>

          <a class="silpo-button" href="{{ route('mcp.oauth.silpo.connect') }}">
            <span class="silpo-icon" aria-hidden="true">
              <svg viewBox="0 0 40 40" role="img">
                <path d="M11 15h20l-2.6 11.5H14L11 15Z" />
                <path d="m15 15 4-6m6 6-4-6" />
                <circle cx="16" cy="30" r="2" />
                <circle cx="27" cy="30" r="2" />
              </svg>
            </span>
            <span>Увійти через Сільпо</span>
            <svg class="arrow-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M5 12h13M13 6l6 6-6 6" />
            </svg>
          </a>
          <p class="oauth-note">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <rect x="6" y="10" width="12" height="10" rx="2" />
              <path d="M8.5 10V7.5a3.5 3.5 0 0 1 7 0V10" />
            </svg>
            Безпечно. Через офіційний OAuth Сільпо
          </p>
        </div>

        <div class="hero-scene" aria-label="Гусь Шо розбирає чат про шашлики">
          <span class="doodle doodle-one" aria-hidden="true">♡</span>
          <span class="doodle doodle-two" aria-hidden="true">⌁</span>
          <img
            class="hero-goose"
            src="{{ asset('images/brand/goose-sho.png') }}"
            alt="Мультяшний гусь із телефоном і торбою продуктів"
          />

          <div class="phone" aria-label="Приклад переписки">
            <div class="phone-topbar">
              <button type="button" aria-label="Назад">‹</button>
              <div>
                <strong>Шашлики</strong>
                <span>9 учасників</span>
              </div>
              <span class="search-icon" aria-hidden="true"></span>
            </div>
            <div class="messages">
              <div class="message-row">
                <span class="avatar avatar-red">О</span>
                <p><b>Оля</b>Я не їм мʼясо 😇</p>
                <time>12:47</time>
              </div>
              <div class="message-row">
                <span class="avatar avatar-green">М</span>
                <p><b>Макс</b>Вугілля беру на себе</p>
                <time>12:48</time>
              </div>
              <div class="message-row">
                <span class="avatar avatar-yellow">Н</span>
                <p><b>Наталя</b>Лише rosé, дякую 🥂</p>
                <time>12:49</time>
              </div>
              <div class="message-row">
                <span class="avatar avatar-blue">І</span>
                <p><b>Ігор</b>Буду з дівчиною</p>
                <time>12:49</time>
              </div>
              <div class="message-own">
                Мені безлактозне морозиво<br />і без грибів, будь ласка
                <time>12:50 ✓✓</time>
              </div>
            </div>
            <div class="composer"><span>Аа</span><b>⌕</b><b>♩</b></div>
          </div>

          <div class="basket-note">Потім — раз!<br />і в кошику</div>
        </div>
      </section>

      <section class="promise shell" aria-labelledby="promise-title">
        <h2 id="promise-title">Для гарної компанії і смачних планів</h2>
        <div class="promise-grid">
          <article>
            <span class="promise-icon chat-icon" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-chat" /></svg>
            </span>
            <h3>Скидаєте чат</h3>
            <p>Скріншоти або текст — хоч із трьох месенджерів.</p>
          </article>
          <article>
            <span class="promise-icon" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-brain" /></svg>
            </span>
            <h3>Ми читаємо</h3>
            <p>Хто буде, що їсть, що принесе й де всі передумали.</p>
          </article>
          <article>
            <span class="promise-icon" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-list" /></svg>
            </span>
            <h3>Формуємо список</h3>
            <p>Тільки потрібне і одразу в нормальній кількості.</p>
          </article>
          <article>
            <span class="promise-icon" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-cart" /></svg>
            </span>
            <h3>Збираємо кошик</h3>
            <p>Підбираємо товари Сільпо — вам лишається перевірити.</p>
          </article>
        </div>
      </section>

      <section class="chat-example" id="example" aria-labelledby="example-title">
        <div class="shell example-shell">
          <div class="section-heading">
            <p class="scribble" aria-hidden="true">48 повідомлень → 1 список</p>
            <h2 id="example-title">Гусь прочитав.<br />Вам не доведеться.</h2>
            <p>
              Навіть те повідомлення, де Макс уже втретє передумав.
              Ми зводимо домовленості в один актуальний стан події.
            </p>
          </div>

          <div class="example-board">
            <div class="chat-card">
              <div class="chat-card-header">
                <div>
                  <strong>Шашлики у Каті</strong>
                  <span>сьогодні</span>
                </div>
                <span>•••</span>
              </div>
              <div class="loose-message left green">
                <b>Оля</b>
                Я буду, але без мʼяса
              </div>
              <div class="loose-message right yellow">
                <b>Макс</b>
                Вугілля беру я 🔥
              </div>
              <div class="loose-message left pink">
                <b>Наталя</b>
                Мені сухе рожеве
              </div>
              <div class="typing">Гусь Шо читає…</div>
            </div>

            <div class="summary-card">
              <div class="summary-top">
                <span>Що зрозуміли</span>
                <b>✓</b>
              </div>
              <h3>Шашлики · 9 людей</h3>
              <ul>
                <li><span>🥩</span><p><b>Мʼясо та риба</b>3.2 кг на 8 людей</p></li>
                <li><span>🌱</span><p><b>Для Олі</b>веганські ковбаски + хумус</p></li>
                <li><span>🍷</span><p><b>Напої</b>rosé ×3, пиво ×12</p></li>
                <li><span>🪨</span><p><b>Не купуємо</b>вугілля приносить Максим</p></li>
              </ul>
              <div class="summary-total"><span>Разом</span><strong>4 731 ₴</strong></div>
            </div>
          </div>
        </div>
      </section>

      <section class="how shell" id="how" aria-labelledby="how-title">
        <div class="how-heading">
          <h2 id="how-title">Як це працює?</h2>
          <p>Складна магія. Простий план.</p>
        </div>

        <ol class="steps">
          <li>
            <span class="step-number">01</span>
            <div class="step-visual paper-phone" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-chat" /></svg>
            </div>
            <h3>Скиньте чат</h3>
            <p>Скріншоти або текст</p>
          </li>
          <li>
            <span class="step-number">02</span>
            <div class="step-visual" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-brain" /></svg>
            </div>
            <h3>Ми розгребемо</h3>
            <p>AI витягне головне</p>
          </li>
          <li>
            <span class="step-number">03</span>
            <div class="step-visual" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-list" /></svg>
            </div>
            <h3>Сплануємо</h3>
            <p>Список і кількості</p>
          </li>
          <li>
            <span class="step-number">04</span>
            <div class="step-visual" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-cart" /></svg>
            </div>
            <h3>Додамо в кошик</h3>
            <p>Ви все перевірите</p>
          </li>
          <li>
            <span class="step-number">05</span>
            <div class="step-visual" aria-hidden="true">
              <svg class="drawn-icon"><use href="#icon-relax" /></svg>
            </div>
            <h3>Відпочивайте</h3>
            <p>Ми все зробили</p>
          </li>
        </ol>
      </section>

      <section class="final-cta" id="about">
        <div class="shell final-inner">
          <div class="quote-card">
            <span aria-hidden="true">“</span>
            <p>Нарешті хтось розгріб цей чат замість нас 😂 Гусь — топ!</p>
            <b>— Катя, організаторка шашликів</b>
          </div>

          <div class="final-copy">
            <h2>Менше переписки.<br /><em>Більше шашликів.</em></h2>
            <a class="silpo-button silpo-button-light" href="{{ route('mcp.oauth.silpo.connect') }}">
              <span class="silpo-icon" aria-hidden="true">
                <svg viewBox="0 0 40 40">
                  <path d="M11 15h20l-2.6 11.5H14L11 15Z" />
                  <path d="m15 15 4-6m6 6-4-6" />
                  <circle cx="16" cy="30" r="2" />
                  <circle cx="27" cy="30" r="2" />
                </svg>
              </span>
              <span>Увійти через Сільпо</span>
              <svg class="arrow-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M5 12h13M13 6l6 6-6 6" />
              </svg>
            </a>
          </div>

          <div class="goose-peek" aria-hidden="true">
            <img src="{{ asset('images/brand/goose-sho.png') }}" alt="" />
          </div>
        </div>
      </section>
    </main>

    <footer class="site-footer">
      <div class="shell footer-inner">
        <a class="brand brand-small" href="#top" aria-label="Хто шо? — на початок">
          <span>ХТО ШО<i>?</i></span>
        </a>
        <p>© 2026 Хто шо? Гусь усе тримає під контролем.</p>
        <div class="footer-links">
          <a href="#privacy-dialog" data-dialog-open="privacy-dialog">Приватність</a>
          <a href="#terms-dialog" data-dialog-open="terms-dialog">Умови</a>
        </div>
      </div>
    </footer>

    <dialog
      class="legal-dialog legal-dialog-privacy"
      id="privacy-dialog"
      aria-labelledby="privacy-title"
      data-legal-dialog
    >
      <article class="legal-card">
        <header class="legal-header">
          <div>
            <p class="legal-eyebrow">Приватність без дрібного шрифту</p>
            <h2 id="privacy-title">Гусь не пхає дзьоб, куди не просили</h2>
            <p>Останнє оновлення: 22 серпня 2026 року</p>
          </div>
          <form method="dialog">
            <button class="legal-close" type="submit" aria-label="Закрити політику приватності">×</button>
          </form>
        </header>

        <div class="legal-body">
          <aside class="legal-owner">
            <span>Хто відповідає за гусака</span>
            <strong>Зайчиков Віктор Сергійович</strong>
            <p>Власник і адміністратор сервісу · ІНН 3197615355</p>
          </aside>

          <section class="legal-section">
            <h3>Що потрапляє до нашого гнізда</h3>
            <ul>
              <li><strong>Вхід через Сільпо.</strong> Дані профілю, які повертає Сільпо, технічний ідентифікатор та OAuth-токени. Пароль від Сільпо ми не бачимо і не зберігаємо.</li>
              <li><strong>Матеріали події.</strong> Назва й опис події, текст, скріншоти, назви файлів, домовленості, вподобання, алергії, обмеження, бюджет та інші дані, які ви самі додаєте.</li>
              <li><strong>Результати роботи.</strong> Розпізнаний текст, висновки AI, попередження, список покупок, вибрані товари та стан синхронізації кошика.</li>
              <li><strong>Технічні дані.</strong> Сесія входу, час дій і помилки, потрібні для безпеки та роботи сервісу. Рекламних трекерів Гусь не розводить.</li>
            </ul>
          </section>

          <section class="legal-section">
            <h3>Навіщо це все</h3>
            <p>Щоб упізнати вас після входу, зберегти ваші події, прочитати надані матеріали, зібрати актуальний план і — лише після вашої команди — підібрати товари або оновити кошик Сільпо. Ми не продаємо ці дані й не будуємо з них рекламні профілі.</p>
          </section>

          <section class="legal-section">
            <h3>Кому щось передається</h3>
            <ul>
              <li><strong>Налаштованому AI-сервісу — OpenAI або Ollama.</strong> Він отримує надане зображення, текст чи потрібний контекст, щоб розпізнати й узагальнити матеріали. OAuth-токени та зайві дані до AI не передаємо.</li>
              <li><strong>Сільпо через офіційний OAuth і MCP.</strong> MCP — це захищене машинне зʼєднання, через яке сервіс може прочитати ваш профіль, знайти товари, перевірити поточний кошик і виконати запитану вами зміну. Оформлення замовлення та оплата сюди не входять.</li>
              <li><strong>Інфраструктурі сервісу.</strong> База даних, приватне сховище файлів і черги обробки тримають інформацію, потрібну для роботи «Хто Шо?».</li>
            </ul>
          </section>

          <section class="legal-section">
            <h3>Як зберігаємо й видаляємо</h3>
            <p>Скріншоти лежать у приватному сховищі, а події та результати — у базі даних. OAuth-токени й знімок профілю Сільпо зберігаються зашифрованими. Дані кожного користувача відокремлені від чужих.</p>
            <p>Джерело або всю подію можна видалити в інтерфейсі. Разом із ними видаляються прикріплені приватні файли та повʼязані результати, щойно вони більше не потрібні іншому вашому джерелу. Не вантажте в чат те, чого Гусю знати не треба.</p>
          </section>

          <div class="legal-note">
            <strong>Коротко:</strong> ви приносите чат — ми працюємо тільки заради вашої події. Ніяких таємних замовлень, продажу даних чи дзьобання паролів.
          </div>

          <form class="legal-actions" method="dialog">
            <button type="submit">Зрозуміло, ґа-ґа</button>
          </form>
        </div>
      </article>
    </dialog>

    <dialog
      class="legal-dialog legal-dialog-terms"
      id="terms-dialog"
      aria-labelledby="terms-title"
      data-legal-dialog
    >
      <article class="legal-card">
        <header class="legal-header">
          <div>
            <p class="legal-eyebrow">Умови без юридичного туману</p>
            <h2 id="terms-title">Домовились на березі. І біля кошика.</h2>
            <p>Останнє оновлення: 22 серпня 2026 року</p>
          </div>
          <form method="dialog">
            <button class="legal-close" type="submit" aria-label="Закрити умови користування">×</button>
          </form>
        </header>

        <div class="legal-body">
          <aside class="legal-owner">
            <span>Власник сервісу</span>
            <strong>Зайчиков Віктор Сергійович</strong>
            <p>ІНН 3197615355</p>
          </aside>

          <section class="legal-section">
            <h3>1. Що це за сервіс</h3>
            <p>«Хто Шо?» допомагає перетворити повідомлення та скріншоти про одну подію на зрозумілий контекст, список покупок і чернетку кошика Сільпо. Це помічник для планування, а не магазин, платіжний сервіс, лікар чи той друг, який «точно памʼятає», у кого алергія.</p>
          </section>

          <section class="legal-section">
            <h3>2. Вхід і ваші матеріали</h3>
            <ul>
              <li>Ви входите через офіційний OAuth Сільпо й дозволяєте сервісу отримати потрібні дані профілю. Окремого пароля для «Хто Шо?» немає.</li>
              <li>Завантажуючи чат, текст або зображення, ви підтверджуєте, що маєте право ними користуватися. Не додавайте чужі секрети, банківські дані та інформацію, без якої подія чудово проживе.</li>
              <li>Ви можете редагувати подію, вилучати окремі джерела або видалити подію повністю.</li>
            </ul>
          </section>

          <section class="legal-section">
            <h3>3. AI може помилитися. Навіть у краватці.</h3>
            <p>Розпізнавання скріншотів і висновки можуть бути неповними або неточними. Перед покупкою перевірте учасників, алергії, дієтичні обмеження, кількості, ціни й товари. Остаточне рішення завжди ваше; сервіс не надає медичних чи дієтологічних рекомендацій.</p>
          </section>

          <section class="legal-section">
            <h3>4. Як працюємо із Сільпо MCP</h3>
            <ul>
              <li>Через MCP сервіс може читати потрібні дані профілю, каталогу, наявності, цін і вашого поточного кошика — у межах можливостей, які надає Сільпо.</li>
              <li>Додавання, заміна або видалення товарів відбувається тільки після вашої явної дії синхронізації. Чужі до події товари в кошику чіпати не повинні.</li>
              <li>«Хто Шо?» не оформлює замовлення, не натискає «Оплатити» і не списує гроші. Кошик залишається чернеткою для вашої перевірки.</li>
              <li>Ціни, асортимент, наявність, доставка й оформлення регулюються самим Сільпо та можуть змінюватися швидше, ніж Гусь встигає сказати «ґа».</li>
            </ul>
          </section>

          <section class="legal-section">
            <h3>5. Нормальна поведінка</h3>
            <p>Не ламайте сервіс, не обходьте захист, не завантажуйте шкідливі файли й не використовуйте «Хто Шо?» для незаконних дій. Ми можемо обмежити доступ, якщо хтось системно псує гніздо іншим.</p>
          </section>

          <section class="legal-section">
            <h3>6. Доступність і зміни</h3>
            <p>Ми стараємося тримати сервіс бадьорим, але не гарантуємо безперервну роботу AI, Сільпо MCP чи інших зовнішніх систем. Умови можуть оновлюватися разом із продуктом; актуальна редакція завжди живе в цьому вікні.</p>
          </section>

          <div class="legal-note">
            <strong>Коротко:</strong> Гусь допомагає, ви перевіряєте, а купуєте й оплачуєте лише ви самі.
          </div>

          <form class="legal-actions" method="dialog">
            <button type="submit">Домовились</button>
          </form>
        </div>
      </article>
    </dialog>

    <script>
      (() => {
        const activeTriggers = new WeakMap();
        const dialogs = document.querySelectorAll('[data-legal-dialog]');

        const openDialog = (dialog, trigger = null) => {
          if (!(dialog instanceof HTMLDialogElement) || dialog.open) {
            return;
          }

          if (trigger instanceof HTMLElement) {
            activeTriggers.set(dialog, trigger);
          }

          dialog.showModal();
        };

        document.querySelectorAll('[data-dialog-open]').forEach((trigger) => {
          trigger.addEventListener('click', (event) => {
            const dialog = document.getElementById(trigger.dataset.dialogOpen);

            if (dialog instanceof HTMLDialogElement && typeof dialog.showModal === 'function') {
              event.preventDefault();
              openDialog(dialog, trigger);
            }
          });
        });

        dialogs.forEach((dialog) => {
          dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
              dialog.close();
            }
          });

          dialog.addEventListener('close', () => {
            if (window.location.hash === `#${dialog.id}`) {
              window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}`);
            }

            activeTriggers.get(dialog)?.focus();
            activeTriggers.delete(dialog);
          });
        });

        const openLinkedDialog = () => {
          const linkedDialog = document.getElementById(window.location.hash.slice(1));

          if (linkedDialog instanceof HTMLDialogElement && typeof linkedDialog.showModal === 'function') {
            openDialog(linkedDialog);
          }
        };

        window.addEventListener('hashchange', openLinkedDialog);
        openLinkedDialog();
      })();
    </script>

</x-layouts.landing>
