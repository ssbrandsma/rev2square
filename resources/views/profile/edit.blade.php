<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if(session('status') && ! in_array(session('status'), ['profile-updated', 'password-updated', 'verification-link-sent'], true))
                <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</p>
            @endif
            <div class="border border-zinc-200 p-4 sm:p-8 bg-white sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="border border-zinc-200 p-4 sm:p-8 bg-white sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">Public profile</h2>
                            <p class="mt-1 text-sm text-gray-600">Everything here is visible to anyone, including anonymous visitors. Your email address never appears on your public profile. <a href="{{ $user->profileUrl() }}" class="font-semibold text-emerald-700 hover:text-emerald-600">Preview public profile</a></p>
                        </header>

                        <form method="post" action="{{ route('profile.public.update') }}" class="mt-6 space-y-6">
                            @csrf
                            @method('patch')

                            <div>
                                <x-input-label for="handle" value="Handle (optional)" />
                                <div class="mt-1 flex items-center gap-1">
                                    <span class="text-sm text-gray-500">@</span>
                                    <x-text-input id="handle" name="handle" type="text" class="block w-full" :value="old('handle', $user->handle)" placeholder="reviver-jane" autocomplete="off" />
                                </div>
                                <p class="mt-1 text-xs text-gray-500">3–30 lowercase letters, numbers, _ or -. Your profile lives at /u/handle. Old handles keep working and stay yours.</p>
                                <x-input-error class="mt-2" :messages="$errors->get('handle')" />
                            </div>

                            <div>
                                <x-input-label for="bio" value="Bio (optional)" />
                                <textarea id="bio" name="bio" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="What do you revive?">{{ old('bio', $user->bio) }}</textarea>
                                <x-input-error class="mt-2" :messages="$errors->get('bio')" />
                            </div>

                            <div>
                                <x-input-label for="skills" value="Skills (optional, comma-separated)" />
                                <x-text-input id="skills" name="skills" type="text" class="mt-1 block w-full" :value="old('skills', implode(', ', $user->skillList()))" placeholder="soldering, firmware, documentation" />
                                <x-input-error class="mt-2" :messages="$errors->get('skills')" />
                            </div>

                            <div>
                                <x-input-label value="Links (optional, up to 5)" />
                                <div class="mt-1 space-y-2">
                                    @for($i = 0; $i < 5; $i++)
                                        @php($existing = $user->linkList()[$i] ?? ['label' => '', 'url' => ''])
                                        <div class="flex gap-2">
                                            <x-text-input name="links[{{ $i }}][label]" type="text" class="block w-1/3" :value="old('links.'.$i.'.label', $existing['label'])" placeholder="Label" aria-label="Link {{ $i + 1 }} label" />
                                            <x-text-input name="links[{{ $i }}][url]" type="url" class="block w-2/3" :value="old('links.'.$i.'.url', $existing['url'])" placeholder="https://…" aria-label="Link {{ $i + 1 }} URL" />
                                        </div>
                                    @endfor
                                </div>
                                <x-input-error class="mt-2" :messages="$errors->get('links.0.url')" />
                            </div>

                            <div>
                                <x-input-label for="availability" value="Availability (optional, self-described)" />
                                <x-text-input id="availability" name="availability" type="text" class="mt-1 block w-full" :value="old('availability', $user->availability)" placeholder="A few hours on weekends" />
                                <x-input-error class="mt-2" :messages="$errors->get('availability')" />
                            </div>

                            <div>
                                <x-input-label for="hardware_interests" value="Hardware interests (optional, self-described)" />
                                <textarea id="hardware_interests" name="hardware_interests" rows="2" maxlength="2000" class="mt-1 block w-full rounded-lg border-zinc-300 shadow-sm focus:border-emerald-500 focus:ring-emerald-500" placeholder="Walkmans, CRTs, …">{{ old('hardware_interests', $user->hardware_interests) }}</textarea>
                                <x-input-error class="mt-2" :messages="$errors->get('hardware_interests')" />
                            </div>

                            <x-primary-button>Save public profile</x-primary-button>
                        </form>
                    </section>
                </div>
            </div>

            <div class="border border-zinc-200 p-4 sm:p-8 bg-white sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">Avatar</h2>
                            <p class="mt-1 text-sm text-gray-600">Shown next to your name across the site. Images are resized and re-encoded; embedded metadata is discarded. Without one, your initials show.</p>
                        </header>

                        <div class="mt-4 flex items-center gap-4">
                            <x-avatar :user="$user" size="lg" />
                            <div class="space-y-2">
                                <form method="post" action="{{ route('avatars.store') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <label for="avatar" class="sr-only">Avatar image</label>
                                    <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required class="text-sm text-gray-600">
                                    <x-primary-button>Upload</x-primary-button>
                                </form>
                                <x-input-error :messages="$errors->get('avatar')" />
                                @if($user->avatar_path)
                                    <form method="post" action="{{ route('avatars.destroy') }}">
                                        @csrf
                                        @method('delete')
                                        <button type="submit" class="text-sm font-semibold text-zinc-500 hover:text-zinc-800">Remove avatar (back to initials)</button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <div class="border border-zinc-200 p-4 sm:p-8 bg-white sm:rounded-lg">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">Presence and notifications</h2>
                            <p class="mt-1 text-sm text-gray-600">Control who sees you online and what lands in your inbox. Chat messages never trigger notifications.</p>
                        </header>

                        <form method="POST" action="{{ route('profile.preferences.update') }}" class="mt-6 space-y-5">
                            @csrf
                            @method('PATCH')

                            <div class="rounded-lg border border-zinc-200 p-4">
                                <label class="flex items-start gap-3">
                                    <input type="checkbox" name="show_presence" value="1" @checked($user->show_presence)
                                        class="mt-1 rounded border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                                    <span>
                                        <span class="block text-sm font-semibold text-gray-900">Show me as online</span>
                                        <span class="block text-sm text-gray-600">Opt in to share your name on the Online now page and the Here now lists. Presence is never stored; leaving a page or opting out removes you immediately. Chatting works the same either way.</span>
                                    </span>
                                </label>
                            </div>

                            <div class="space-y-3">
                                <p class="text-sm font-semibold text-gray-900">Notify me about</p>
                                <label class="flex items-center gap-3 text-sm text-gray-700">
                                    <input type="checkbox" name="notify_discussion_replies" value="1" @checked($user->wantsNotification('discussion_replies'))
                                        class="rounded border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                                    Replies in discussions I started or follow
                                </label>
                                <label class="flex items-center gap-3 text-sm text-gray-700">
                                    <input type="checkbox" name="notify_mentions" value="1" @checked($user->wantsNotification('mentions'))
                                        class="rounded border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                                    @-mentions of me
                                </label>
                                <label class="flex items-center gap-3 text-sm text-gray-700">
                                    <input type="checkbox" name="notify_project_updates" value="1" @checked($user->wantsNotification('project_updates'))
                                        class="rounded border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                                    Official updates on projects I follow
                                </label>
                                <label class="flex items-center gap-3 text-sm text-gray-700">
                                    <input type="checkbox" name="notify_followed_posts" value="1" @checked($user->wantsNotification('followed_posts'))
                                        class="rounded border-zinc-300 text-emerald-600 shadow-sm focus:ring-emerald-500">
                                    Every new discussion on projects I follow (noisy)
                                </label>
                            </div>

                            <button type="submit" class="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-500">Save preferences</button>
                        </form>
                    </section>
                </div>
            </div>

            <div class="border border-zinc-200 p-4 sm:p-8 bg-white sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="border border-zinc-200 p-4 sm:p-8 bg-white sm:rounded-lg">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
