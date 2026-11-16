<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ArrowLeft, Calculator, Check, Copy, Delete, History, Trash2 } from 'lucide-vue-next';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface HistoryEntry {
    equation: string;
    result: string;
    time: string;
}

const open = ref(false);
const showHistory = ref(false);
const history = ref<HistoryEntry[]>([]);
const copiedItemIndex = ref<number | null>(null);

const display = ref('0');
const equation = ref('');
const tokens = ref<(number | string)[]>([]);
const waitingForOperand = ref(false);
const hasCalculated = ref(false);
const copied = ref(false);
const equationContainer = ref<HTMLElement | null>(null);

const STORAGE_KEY = 'pos_calc_history';

onMounted(() => {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) {
            history.value = JSON.parse(stored);
        }
    } catch {
        // Ignore storage errors
    }
});

function saveHistory() {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(history.value.slice(0, 50)));
    } catch {
        // Ignore storage errors
    }
}

function clearHistory() {
    history.value = [];
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        // Ignore
    }
}

function addHistoryEntry(eq: string, res: string) {
    const timeStr = new Date().toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' });
    history.value.unshift({
        equation: eq,
        result: res,
        time: timeStr,
    });
    saveHistory();
}

function opSymbol(op: string) {
    switch (op) {
        case '+':
            return '+';
        case '-':
            return '−';
        case '*':
            return '×';
        case '/':
            return '÷';
        default:
            return op;
    }
}

function updateEquationDisplay() {
    equation.value = tokens.value.map((t, idx) => (idx % 2 === 1 ? opSymbol(String(t)) : String(t))).join(' ');
}

function evaluateTokens(toks: (number | string)[]): number {
    if (toks.length === 0) return 0;
    if (toks.length === 1) return Number(toks[0]) || 0;

    // Pass 1: resolve multiplication and division (* and /)
    const pass1: (number | string)[] = [];
    let i = 0;
    while (i < toks.length) {
        const token = toks[i];
        if (token === '*' || token === '/') {
            const prev = Number(pass1.pop());
            const next = Number(toks[i + 1]);
            const res = token === '*' ? prev * next : next === 0 ? 0 : prev / next;
            pass1.push(Math.round(res * 1e10) / 1e10);
            i += 2;
        } else {
            pass1.push(token);
            i++;
        }
    }

    // Pass 2: resolve addition and subtraction (+ and -)
    let total = Number(pass1[0]) || 0;
    for (let j = 1; j < pass1.length; j += 2) {
        const op = pass1[j];
        const next = Number(pass1[j + 1]) || 0;
        if (op === '+') {
            total += next;
        } else if (op === '-') {
            total -= next;
        }
        total = Math.round(total * 1e10) / 1e10;
    }

    return total;
}

function inputDigit(digit: string) {
    if (hasCalculated.value) {
        hasCalculated.value = false;
        tokens.value = [];
        equation.value = '';
        display.value = digit;
        waitingForOperand.value = false;
        return;
    }

    if (waitingForOperand.value) {
        display.value = digit;
        waitingForOperand.value = false;
    } else {
        display.value = display.value === '0' ? digit : display.value + digit;
    }
}

function inputDot() {
    if (hasCalculated.value) {
        hasCalculated.value = false;
        tokens.value = [];
        equation.value = '';
        display.value = '0.';
        waitingForOperand.value = false;
        return;
    }

    if (waitingForOperand.value) {
        display.value = '0.';
        waitingForOperand.value = false;
        return;
    }
    if (!display.value.includes('.')) {
        display.value += '.';
    }
}

function performOperation(nextOperator: string) {
    // If user just evaluated an equation with '=', continue chaining from that result
    if (hasCalculated.value) {
        const currentVal = parseFloat(display.value) || 0;
        tokens.value = [currentVal, nextOperator];
        hasCalculated.value = false;
        waitingForOperand.value = true;
        updateEquationDisplay();
        return;
    }

    // If waiting for operand and tokens has elements, user is changing the trailing operator
    if (waitingForOperand.value && tokens.value.length > 0) {
        tokens.value[tokens.value.length - 1] = nextOperator;
        updateEquationDisplay();
        return;
    }

    const currentVal = parseFloat(display.value) || 0;
    tokens.value.push(currentVal, nextOperator);

    // Calculate intermediate running result for the display
    const runningResult = evaluateTokens(tokens.value.slice(0, -1));
    display.value = String(runningResult);

    waitingForOperand.value = true;
    updateEquationDisplay();
}

function equals() {
    if (tokens.value.length === 0 || hasCalculated.value) return;

    const currentVal = parseFloat(display.value) || 0;
    tokens.value.push(currentVal);

    const result = evaluateTokens(tokens.value);

    // Build the complete equation string: e.g. "5 × 6 × 9 + 7 + 7 − 9 ="
    const fullEquation = tokens.value.map((t, idx) => (idx % 2 === 1 ? opSymbol(String(t)) : String(t))).join(' ') + ' =';

    equation.value = fullEquation;
    display.value = String(result);
    addHistoryEntry(fullEquation, String(result));

    hasCalculated.value = true;
    tokens.value = [];
    waitingForOperand.value = true;
}

function clear() {
    display.value = '0';
    equation.value = '';
    tokens.value = [];
    waitingForOperand.value = false;
    hasCalculated.value = false;
}

function backspace() {
    if (waitingForOperand.value || hasCalculated.value) return;
    if (display.value.length === 1 || (display.value.length === 2 && display.value.startsWith('-'))) {
        display.value = '0';
    } else {
        display.value = display.value.slice(0, -1);
    }
}

function toggleSign() {
    const val = parseFloat(display.value);
    if (isNaN(val) || val === 0) return;
    display.value = String(-val);
}

function percentage() {
    const val = parseFloat(display.value);
    if (isNaN(val)) return;
    display.value = String(val / 100);
}

function useHistoryItem(item: HistoryEntry) {
    display.value = item.result;
    equation.value = item.equation;
    tokens.value = [];
    waitingForOperand.value = true;
    hasCalculated.value = true;
    showHistory.value = false;
}

function copyText(text: string, index?: number) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(text);
    if (index !== undefined) {
        copiedItemIndex.value = index;
        setTimeout(() => {
            copiedItemIndex.value = null;
        }, 1500);
    } else {
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 1500);
    }
}

function handleKeyDown(e: KeyboardEvent) {
    if (!open.value) return;

    if (e.key >= '0' && e.key <= '9') {
        e.preventDefault();
        inputDigit(e.key);
    } else if (e.key === '.') {
        e.preventDefault();
        inputDot();
    } else if (e.key === '+' || e.key === '-' || e.key === '*' || e.key === '/') {
        e.preventDefault();
        performOperation(e.key);
    } else if (e.key === 'x' || e.key === 'X') {
        e.preventDefault();
        performOperation('*');
    } else if (e.key === 'Enter' || e.key === '=') {
        e.preventDefault();
        equals();
    } else if (e.key === 'Backspace') {
        e.preventDefault();
        backspace();
    } else if (e.key === 'Escape') {
        e.preventDefault();
        open.value = false;
    } else if (e.key === 'c' || e.key === 'C') {
        e.preventDefault();
        clear();
    } else if (e.key === 'h' || e.key === 'H') {
        e.preventDefault();
        showHistory.value = !showHistory.value;
    }
}

watch(equation, () => {
    nextTick(() => {
        if (equationContainer.value) {
            equationContainer.value.scrollLeft = equationContainer.value.scrollWidth;
        }
    });
});

watch(open, (isOpen) => {
    if (isOpen) {
        window.addEventListener('keydown', handleKeyDown);
    } else {
        window.removeEventListener('keydown', handleKeyDown);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeyDown);
});
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="press flex h-9 select-none items-center gap-1.5 rounded-md border border-input/80 bg-card/60 px-2.5 text-xs font-medium text-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
                title="Calculator"
                aria-label="Open calculator"
            >
                <Calculator class="size-4 text-primary" />
                <span class="hidden xl:inline">Calculator</span>
            </button>
        </PopoverTrigger>

        <PopoverContent align="end" class="w-72 p-3 shadow-xl">
            <!-- Top bar -->
            <div class="mb-2.5 flex items-center justify-between border-b border-border/50 pb-2 text-xs">
                <div class="flex items-center gap-1.5 font-medium text-foreground">
                    <Calculator class="size-3.5 text-primary" />
                    <span>Calculator</span>
                </div>
                <button
                    type="button"
                    class="press flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-medium transition-colors"
                    :class="showHistory ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                    :title="showHistory ? 'Back to keypad' : 'View history (H)'"
                    @click="showHistory = !showHistory"
                >
                    <ArrowLeft v-if="showHistory" class="size-3" />
                    <History v-else class="size-3.5" />
                    <span>{{ showHistory ? 'Keypad' : 'History' }}</span>
                    <span v-if="!showHistory && history.length" class="ml-0.5 rounded-full bg-primary/15 px-1 font-mono text-[10px] text-primary">
                        {{ history.length }}
                    </span>
                </button>
            </div>

            <!-- View: History -->
            <div v-if="showHistory" class="space-y-2">
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span class="font-medium">Recent calculations</span>
                    <button
                        v-if="history.length"
                        type="button"
                        class="press flex items-center gap-1 text-[11px] text-destructive hover:underline"
                        title="Clear history"
                        @click="clearHistory"
                    >
                        <Trash2 class="size-3" />
                        <span>Clear</span>
                    </button>
                </div>

                <div v-if="history.length" class="max-h-64 space-y-1.5 overflow-y-auto pr-0.5">
                    <div
                        v-for="(item, idx) in history"
                        :key="idx"
                        class="group flex cursor-pointer items-center justify-between rounded-lg border border-border/40 bg-muted/20 p-2 transition-colors hover:border-primary/40 hover:bg-accent/40"
                        title="Click to load into calculator"
                        @click="useHistoryItem(item)"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-xs text-muted-foreground">{{ item.equation }}</p>
                            <p class="truncate font-mono text-base font-bold text-foreground">{{ item.result }}</p>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-[10px] text-muted-foreground/70">{{ item.time }}</span>
                            <button
                                type="button"
                                class="press rounded p-1 text-muted-foreground hover:bg-background hover:text-foreground"
                                title="Copy result"
                                @click.stop="copyText(item.result, idx)"
                            >
                                <Check v-if="copiedItemIndex === idx" class="size-3.5 text-primary" />
                                <Copy v-else class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="py-8 text-center text-muted-foreground">
                    <History class="mx-auto mb-1.5 size-7 text-muted-foreground/40" />
                    <p class="text-xs font-medium">No calculation history</p>
                    <p class="text-[11px] text-muted-foreground/70">Completed calculations appear here</p>
                </div>

                <Button variant="outline" size="sm" class="w-full text-xs" @click="showHistory = false"> Back to Keypad </Button>
            </div>

            <!-- View: Keypad & Display -->
            <div v-else>
                <!-- Display -->
                <div class="mb-3 rounded-lg border border-border/60 bg-muted/40 p-2.5 text-right">
                    <div ref="equationContainer" class="scrollbar-none h-4.5 overflow-x-auto whitespace-nowrap text-right">
                        <p class="font-mono text-xs text-muted-foreground">{{ equation || '\u00A0' }}</p>
                    </div>
                    <div class="mt-1 flex items-center justify-between gap-2">
                        <div class="flex items-center gap-1">
                            <button
                                type="button"
                                class="press rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                title="Copy to clipboard"
                                @click="copyText(display)"
                            >
                                <Check v-if="copied" class="size-3.5 text-primary" />
                                <Copy v-else class="size-3.5" />
                            </button>
                            <button
                                type="button"
                                class="press rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground"
                                title="View history (H)"
                                @click="showHistory = true"
                            >
                                <History class="size-3.5" />
                            </button>
                        </div>
                        <span class="select-all truncate font-mono text-2xl font-bold tracking-tight text-foreground">
                            {{ display }}
                        </span>
                    </div>
                </div>

                <!-- Keypad -->
                <div class="grid grid-cols-4 gap-1.5">
                    <!-- Row 1 -->
                    <Button
                        variant="secondary"
                        size="sm"
                        class="h-9 font-medium text-destructive hover:bg-destructive/10 hover:text-destructive"
                        @click="clear"
                    >
                        AC
                    </Button>
                    <Button variant="secondary" size="sm" class="h-9 font-medium" @click="toggleSign"> ± </Button>
                    <Button variant="secondary" size="sm" class="h-9 font-medium" @click="percentage"> % </Button>
                    <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('/')"> ÷ </Button>

                    <!-- Row 2 -->
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('7')">7</Button>
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('8')">8</Button>
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('9')">9</Button>
                    <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('*')"> × </Button>

                    <!-- Row 3 -->
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('4')">4</Button>
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('5')">5</Button>
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('6')">6</Button>
                    <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('-')"> − </Button>

                    <!-- Row 4 -->
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('1')">1</Button>
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('2')">2</Button>
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('3')">3</Button>
                    <Button variant="secondary" size="sm" class="h-9 font-semibold text-primary" @click="performOperation('+')"> + </Button>

                    <!-- Row 5 -->
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDigit('0')">0</Button>
                    <Button variant="outline" size="sm" class="h-9 text-sm font-medium" @click="inputDot">.</Button>
                    <Button variant="ghost" size="sm" class="h-9 text-muted-foreground hover:text-foreground" title="Backspace" @click="backspace">
                        <Delete class="size-4" />
                    </Button>
                    <Button size="sm" class="h-9 bg-primary font-bold text-primary-foreground hover:bg-primary/90" @click="equals"> = </Button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
