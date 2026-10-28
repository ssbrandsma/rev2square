// Livewire v4 bundles and starts the frontend runtime itself (see the
// livewire scripts include in the app layout). Bootstrapping a second copy
// here caused "Detected multiple instances of Alpine running", so Alpine is
// deliberately untouched. This entry only wires page-level realtime modules
// (chat, presence); each module self-initializes when its DOM is present.
import '../js/pages/chat-room.js';
import '../js/pages/presence-roster.js';
import '../js/pages/chat-launcher.js';
import '../js/pages/discussion-composer.js';
import '../js/pages/architecture-diagram.js';
