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
        <div>
          <a href="#top">Приватність</a>
          <a href="#top">Умови</a>
        </div>
      </div>
    </footer>

</x-layouts.landing>
